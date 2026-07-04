<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Mockery;
use Mockery\MockInterface;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\TestCase;

class DebugToolingTest extends TestCase
{
    private RedisConnectionResolver|MockInterface $connectionResolver;

    private MockInterface $redis;

    private RedisModelService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock('Illuminate\Redis\Connections\Connection');
        $this->connectionResolver = Mockery::mock(RedisConnectionResolver::class);
        $this->connectionResolver->shouldReceive('resolve')->andReturn($this->redis);
        $this->connectionResolver->shouldReceive('getPrefix')->andReturn('');

        $matchStrategy = Mockery::mock(ModelMatchStrategy::class);
        $matchStrategy->shouldReceive('normalize')->andReturnUsing(fn ($v) => $v);
        $matchStrategy->shouldReceive('matches')->andReturnUsing(fn ($a, $b) => $a === $b);

        $this->service = new TestableDebugService(
            connectionResolver: $this->connectionResolver,
            model_class: DebugTestModel::class,
            indexes: ['role_id', 'status'],
            sorted: ['created_at'],
            custom_indexes: ['active_admins' => ['role_id' => 1, 'status' => 'active']],
            ttl: 3600,
            matchStrategy: $matchStrategy
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_debug_returns_self_enables_debug_mode(): void
    {
        $result = $this->service->debug();

        $this->assertSame($this->service, $result);
    }

    public function test_debug_mode_does_not_affect_inspect_result(): void
    {
        $payload = json_encode([
            'attributes' => ['id' => 42, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01'],
            'relations' => [],
        ], JSON_THROW_ON_ERROR);

        $this->redis->shouldReceive('hget')
            ->with('{debug_test_models}:hash', '42')
            ->andReturn($payload);

        $this->redis->shouldReceive('smembers')
            ->with('{debug_test_models}:index:role_id:1')
            ->andReturn(['42']);

        $this->redis->shouldReceive('smembers')
            ->with('{debug_test_models}:index:status:active')
            ->andReturn(['42']);

        $this->redis->shouldReceive('zscore')
            ->with('{debug_test_models}:sorted:created_at', '42')
            ->andReturn(1704067200.0);

        $this->redis->shouldReceive('smembers')
            ->with('{debug_test_models}:custom:active_admins')
            ->andReturn(['42']);

        $this->redis->shouldReceive('hget')
            ->with('{debug_test_models}:meta', 'cached_at')
            ->andReturn('1704067200');

        $this->redis->shouldReceive('ttl')
            ->with('{debug_test_models}:hash')
            ->andReturn(1800);

        $result = $this->service->debug()->inspect(42);

        $this->assertIsArray($result);
        $this->assertEquals(42, $result['model_id']);
    }

    public function test_inspect_returns_all_keys_for_model_id(): void
    {
        $payload = json_encode([
            'attributes' => ['id' => 42, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01'],
            'relations' => [],
        ], JSON_THROW_ON_ERROR);

        $this->redis->shouldReceive('hget')
            ->with('{debug_test_models}:hash', '42')
            ->andReturn($payload);

        $this->redis->shouldReceive('smembers')
            ->with('{debug_test_models}:index:role_id:1')
            ->andReturn(['42']);

        $this->redis->shouldReceive('smembers')
            ->with('{debug_test_models}:index:status:active')
            ->andReturn(['42']);

        $this->redis->shouldReceive('zscore')
            ->with('{debug_test_models}:sorted:created_at', '42')
            ->andReturn(1704067200.0);

        $this->redis->shouldReceive('smembers')
            ->with('{debug_test_models}:custom:active_admins')
            ->andReturn(['42']);

        $this->redis->shouldReceive('hget')
            ->with('{debug_test_models}:meta', 'cached_at')
            ->andReturn('1704067200');

        $this->redis->shouldReceive('ttl')
            ->with('{debug_test_models}:hash')
            ->andReturn(1800);

        $result = $this->service->inspect(42);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('model_id', $result);
        $this->assertArrayHasKey('model_class', $result);
        $this->assertArrayHasKey('hash_key', $result);
        $this->assertArrayHasKey('hash_data', $result);
        $this->assertArrayHasKey('ttl_remaining', $result);
        $this->assertArrayHasKey('indexes', $result);
        $this->assertArrayHasKey('sorted', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals(42, $result['model_id']);
        $this->assertEquals(DebugTestModel::class, $result['model_class']);
        $this->assertEquals('{debug_test_models}:hash', $result['hash_key']);
        $this->assertEquals(1800, $result['ttl_remaining']);
    }

    public function test_inspect_returns_null_when_model_not_cached(): void
    {
        $this->redis->shouldReceive('hget')
            ->with('{debug_test_models}:hash', '999')
            ->andReturn(false);

        $result = $this->service->inspect(999);

        $this->assertNull($result);
    }

    public function test_analyze_indexes_returns_cardinality_report(): void
    {
        $this->redis->shouldReceive('hlen')
            ->with('{debug_test_models}:hash')
            ->andReturn(100);

        $this->redis->shouldReceive('scard')
            ->with('{debug_test_models}:index:role_id:1')
            ->andReturn(30);

        $this->redis->shouldReceive('scard')
            ->with('{debug_test_models}:index:status:active')
            ->andReturn(60);

        $this->redis->shouldReceive('scard')
            ->with('{debug_test_models}:index:status:inactive')
            ->andReturn(40);

        $this->redis->shouldReceive('scard')
            ->with('{debug_test_models}:custom:active_admins')
            ->andReturn(20);

        $this->redis->shouldReceive('zcard')
            ->with('{debug_test_models}:sorted:created_at')
            ->andReturn(100);

        $this->redis->shouldReceive('hget')
            ->with('{debug_test_models}:meta', 'cached_at')
            ->andReturn('1704067200');

        $this->redis->shouldReceive('ttl')
            ->with('{debug_test_models}:hash')
            ->andReturn(1800);

        $this->redis->shouldReceive('scan')
            ->with('0', ['match' => '{debug_test_models}:index:*', 'count' => 1000])
            ->andReturn(['0', [
                '{debug_test_models}:index:role_id:1',
                '{debug_test_models}:index:status:active',
                '{debug_test_models}:index:status:inactive',
            ]]);

        $result = $this->service->analyzeIndexes();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('model_class', $result);
        $this->assertArrayHasKey('table', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('indexes', $result);
        $this->assertArrayHasKey('sorted', $result);
        $this->assertArrayHasKey('custom_indexes', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals(DebugTestModel::class, $result['model_class']);
        $this->assertEquals(100, $result['hash']['total_models']);
        $this->assertCount(3, $result['indexes']);
    }

    public function test_analyze_indexes_returns_empty_indexes_when_no_data(): void
    {
        $this->redis->shouldReceive('hlen')
            ->with('{debug_test_models}:hash')
            ->andReturn(0);

        $this->redis->shouldReceive('hget')
            ->with('{debug_test_models}:meta', 'cached_at')
            ->andReturn(null);

        $this->redis->shouldReceive('ttl')
            ->with('{debug_test_models}:hash')
            ->andReturn(-2);

        $this->redis->shouldReceive('zcard')
            ->with('{debug_test_models}:sorted:created_at')
            ->andReturn(0);

        $this->redis->shouldReceive('scard')
            ->with('{debug_test_models}:custom:active_admins')
            ->andReturn(0);

        $this->redis->shouldReceive('scan')
            ->with('0', ['match' => '{debug_test_models}:index:*', 'count' => 1000])
            ->andReturn(['0', []]);

        $result = $this->service->analyzeIndexes();

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['hash']['total_models']);
        $this->assertEmpty($result['indexes']);
    }
}

class TestableDebugService extends RedisModelService
{
    protected function collectKeysByPattern(string $pattern): array
    {
        $count = (int) config('redis-model-cache.scan_count', 1000);
        $keys = [];

        $cursor = '0';
        do {
            $result = $this->redis->scan($cursor, ['match' => $pattern, 'count' => $count]);
            $cursor = (string) ($result[0] ?? '0');
            $chunk = $result[1] ?? [];
            if (! empty($chunk)) {
                $keys = array_merge($keys, $chunk);
            }
        } while ($cursor !== '0');

        return array_values(array_unique($keys));
    }
}

class DebugTestModel extends Model
{
    protected $table = 'debug_test_models';

    protected $guarded = [];

    public $timestamps = false;
}
