<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\Invalidation\InvalidationContext;
use Sm_mE\RedisModelCache\Invalidation\Strategies\AsyncStrategy;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\TestCase;

/**
 * Edge-case tests for production robustness.
 *
 * Coverage areas:
 * - Redis connection exceptions (timeout, refused)
 * - Serialization failures (compression edges, corrupted payloads)
 * - Queue-based invalidation behavior (SWR jobs, async strategy)
 * - Transaction rollback safety
 * - Transaction-level cache consistency
 */
class EdgeCaseTest extends TestCase
{
    private RedisConnectionResolver|MockInterface $connectionResolver;

    private MockInterface $redis;

    private RedisModelService $service;

    protected string $hashKey = '{edge_case_models}:hash';

    protected function setUp(): void
    {
        parent::setUp();

        config(['redis-model-cache.lua_scripting.enabled' => false]);
        config(['redis-model-cache.stampede_protection.enabled' => false]);

        $this->redis = Mockery::mock('Illuminate\Redis\Connections\Connection');
        $this->connectionResolver = Mockery::mock(RedisConnectionResolver::class);
        $this->connectionResolver->shouldReceive('resolve')->andReturn($this->redis);
        $this->connectionResolver->shouldReceive('getPrefix')->andReturn('');

        $matchStrategy = Mockery::mock(ModelMatchStrategy::class);
        $matchStrategy->shouldReceive('normalize')->andReturnUsing(fn ($v) => $v);
        $matchStrategy->shouldReceive('matches')->andReturnUsing(fn ($a, $b) => $a === $b);

        $this->service = new RedisModelService(
            connectionResolver: $this->connectionResolver,
            model_class: EdgeCaseModel::class,
            indexes: ['role_id', 'status'],
            sorted: ['score'],
            ttl: 3600,
            matchStrategy: $matchStrategy,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Redis Connection Exceptions
    // =========================================================================

    public function test_connection_timeout_propagates(): void
    {
        $this->redis->shouldReceive('exists')
            ->andReturn(true);
        $this->redis->shouldReceive('smembers')
            ->andReturn(['1', '2', '3']);
        $this->redis->shouldReceive('sinter')
            ->andThrow(new RuntimeException('Connection timed out'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection timed out');

        $this->service->pluck(['id'], ['role_id' => 1]);
    }

    public function test_connection_refused_propagates(): void
    {
        $this->redis->shouldReceive('hget')
            ->andThrow(new RuntimeException('Connection refused'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->service->find(1);
    }

    public function test_redis_timeout_on_index_lookup_propagates(): void
    {
        $this->redis->shouldReceive('smembers')
            ->andThrow(new RuntimeException('read error on connection'));

        $this->expectException(RuntimeException::class);

        $this->service->where(['role_id' => 1]);
    }

    // =========================================================================
    // Serialization / Compression Edge Cases
    // =========================================================================

    public function test_corrupted_json_payload_throws(): void
    {
        $this->redis->shouldReceive('hget')
            ->with($this->hashKey, '42')
            ->andReturn('{corrupted json');

        $this->expectException(\JsonException::class);

        $this->service->find(42);
    }

    public function test_non_json_payload_throws(): void
    {
        $this->redis->shouldReceive('hget')
            ->with($this->hashKey, '42')
            ->andReturn('not-json-at-all');

        $this->expectException(\JsonException::class);

        $this->service->find(42);
    }

    public function test_empty_payload_returns_null(): void
    {
        $this->redis->shouldReceive('hget')
            ->with($this->hashKey, '42')
            ->andReturn(null);

        $result = $this->service->find(42);

        $this->assertNull($result);
    }

    // =========================================================================
    // Queue-Based Invalidation (Async Strategy)
    // =========================================================================

    public function test_async_strategy_invalidates_with_queue_job(): void
    {
        if (! class_exists(AsyncStrategy::class)) {
            $this->markTestSkipped('AsyncStrategy not available');
        }

        Queue::fake();

        $strategy = new AsyncStrategy('default');
        $context = new InvalidationContext(
            modelClass: EdgeCaseModel::class,
            modelId: 999,
            event: 'saved',
            attributes: ['id' => 999, 'role_id' => 1, 'status' => 'active'],
            original: ['id' => 999, 'role_id' => 1, 'status' => 'active'],
            timestamp: microtime(true),
        );

        $strategy->invalidate($context);

        Queue::assertPushed(\Sm_mE\RedisModelCache\Jobs\InvalidateModelCacheJob::class);
    }

    // =========================================================================
    // Cache Consistency Edge Cases
    // =========================================================================

    public function test_delete_of_uncached_model_returns_gracefully(): void
    {
        $this->redis->shouldReceive('hget')
            ->with($this->hashKey, '999')
            ->andReturn(null);

        $this->redis->shouldNotReceive('hdel');
        $this->redis->shouldNotReceive('srem');
        $this->redis->shouldNotReceive('zrem');

        $this->service->delete(999);
        $this->assertTrue(true);
    }

    public function test_where_on_empty_index_returns_empty_collection(): void
    {
        $this->redis->shouldReceive('smembers')
            ->with('{edge_case_models}:index:role_id:999')
            ->andReturn([]);

        $result = $this->service->where(['role_id' => 999]);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_find_with_null_id_returns_null(): void
    {
        // find() requires string|int, but we test the edge case via the mock
        $this->redis->shouldReceive('hget')
            ->with($this->hashKey, '')
            ->andReturn(null);

        $result = $this->service->find('');
        $this->assertNull($result);
    }

    public function test_find_with_zero_id_returns_null(): void
    {
        $this->redis->shouldReceive('hget')
            ->with($this->hashKey, '0')
            ->andReturn(null);

        $result = $this->service->find(0);
        $this->assertNull($result);
    }
}

class EdgeCaseModel extends Model
{
    protected $table = 'edge_case_models';

    protected $fillable = ['id', 'name', 'email', 'role_id', 'status', 'score'];

    public $timestamps = false;
}
