<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Support;

use Illuminate\Database\Eloquent\Model;
use Mockery;
use Mockery\MockInterface;
use Sm_mE\RedisModelCache\Concerns\HasRedisModelCache;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\Support\CacheManager;
use Sm_mE\RedisModelCache\Support\CacheMetrics;
use Sm_mE\RedisModelCache\Support\ExplainResult;
use Sm_mE\RedisModelCache\Support\Observability;
use Sm_mE\RedisModelCache\Tests\TestCase;

class CacheManagerTest extends TestCase
{
    private RedisConnectionResolver|MockInterface $connectionResolver;

    private MockInterface $redis;

    private Observability|MockInterface $observability;

    private CacheManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock('Illuminate\Redis\Connections\Connection');
        $this->connectionResolver = Mockery::mock(RedisConnectionResolver::class);
        $this->connectionResolver->shouldReceive('resolve')->andReturn($this->redis);
        $this->connectionResolver->shouldReceive('getPrefix')->andReturn('');

        $this->observability = Mockery::mock(Observability::class);

        $this->manager = new CacheManager(
            connectionResolver: $this->connectionResolver,
            observability: $this->observability,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_metrics_returns_cache_metrics_instance(): void
    {
        $this->observability->shouldReceive('snapshot')->once()->andReturn([
            'hits' => 42,
            'misses' => 8,
            'total_requests' => 50,
            'hit_rate' => 84.0,
            'miss_rate' => 16.0,
            'latency' => [
                'p50' => 2.5,
                'p95' => 10.1,
                'p99' => 50.3,
                'average' => 5.2,
                'min' => 0.5,
                'max' => 75.0,
                'samples' => 50,
            ],
            'pipeline_size' => [
                'min' => 1,
                'max' => 100,
                'average' => 25.5,
                'median' => 20,
                'samples' => [1, 5, 10, 20, 50, 100],
            ],
            'stale_cleanup' => [
                'count' => 3,
                'keys_removed' => 15,
            ],
            'lock_contention' => 1,
        ]);

        $this->redis->shouldReceive('info')->once()->andReturn([
            'redis_version' => '7.2.0',
            'used_memory' => 1048576,
            'used_memory_peak' => 2097152,
            'uptime_in_seconds' => 86400,
            'connected_clients' => 5,
            'expired_keys' => 100,
        ]);

        $metrics = $this->manager->metrics();

        $this->assertInstanceOf(CacheMetrics::class, $metrics);
        $this->assertSame(42, $metrics->requests['hits']);
        $this->assertSame(8, $metrics->requests['misses']);
        $this->assertSame(84.0, $metrics->requests['hit_rate']);
        $this->assertSame('7.2.0', $metrics->redis['version']);
        $this->assertSame(1048576, $metrics->redis['used_memory']);
        $this->assertSame(2.5, $metrics->latency['p50']);
        $this->assertSame(3, $metrics->staleCleanup['count']);
        $this->assertSame(1, $metrics->lockContention);
    }

    public function test_metrics_to_array_returns_expected_structure(): void
    {
        $this->observability->shouldReceive('snapshot')->once()->andReturn([
            'hits' => 0,
            'misses' => 0,
            'total_requests' => 0,
            'hit_rate' => null,
            'miss_rate' => null,
            'latency' => [
                'p50' => null,
                'p95' => null,
                'p99' => null,
                'average' => null,
                'min' => null,
                'max' => null,
                'samples' => 0,
            ],
            'pipeline_size' => [
                'min' => null,
                'max' => null,
                'average' => null,
                'median' => null,
                'samples' => [],
            ],
            'stale_cleanup' => [
                'count' => 0,
                'keys_removed' => 0,
            ],
            'lock_contention' => 0,
        ]);

        $this->redis->shouldReceive('info')->once()->andReturn([
            'redis_version' => '7.2.0',
            'used_memory' => 0,
            'used_memory_peak' => 0,
            'uptime_in_seconds' => 100,
            'connected_clients' => 1,
            'expired_keys' => 0,
        ]);

        $metrics = $this->manager->metrics();
        $array = $metrics->toArray();

        $this->assertArrayHasKey('requests', $array);
        $this->assertArrayHasKey('redis', $array);
        $this->assertArrayHasKey('latency', $array);
        $this->assertArrayHasKey('pipeline_distribution', $array);
        $this->assertArrayHasKey('stale_cleanup', $array);
        $this->assertArrayHasKey('lock_contention', $array);
    }

    public function test_explain_with_array_query_returns_explain_result(): void
    {
        $modelClass = CacheTestModel::class;

        $this->redis->shouldReceive('smembers')
            ->with('{cache_test_models}:index:status:active')
            ->andReturn([]);

        $result = $this->manager->explain($modelClass, ['status' => 'active']);

        $this->assertInstanceOf(ExplainResult::class, $result);
        $this->assertSame('where', $result->operation);
        $this->assertSame(['status' => 'active'], $result->parameters);
        $this->assertSame(2, $result->totalCommands);
    }

    public function test_explain_with_callback_returns_explain_result(): void
    {
        $modelClass = CacheTestModel::class;

        $this->redis->shouldReceive('smembers')
            ->with('{cache_test_models}:index:status:active')
            ->andReturn([]);

        $result = $this->manager->explain(
            $modelClass,
            fn ($service) => $service->where(['status' => 'active'])
        );

        $this->assertInstanceOf(ExplainResult::class, $result);
        $this->assertSame('where', $result->operation);
    }

    public function test_explain_throws_on_non_explain_result_return(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('did not return an ExplainResult');

        $this->manager->explain(CacheTestModel::class, fn () => 'not-an-explain-result');
    }

    public function test_explain_resolves_model_config_from_trait(): void
    {
        $modelClass = TraitedCacheTestModel::class;

        $this->redis->shouldReceive('smembers')
            ->with('{traited_cache_test_models}:index:role_id:2')
            ->andReturn([]);

        $result = $this->manager->explain($modelClass, ['role_id' => 2]);

        $this->assertInstanceOf(ExplainResult::class, $result);
    }
}

class CacheTestModel extends Model
{
    use HasRedisModelCache;

    protected $table = 'cache_test_models';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    public static function redisModelCacheConfig(): array
    {
        return [
            'indexes' => ['status'],
            'ttl' => 3600,
        ];
    }
}

class TraitedCacheTestModel extends Model
{
    use HasRedisModelCache;

    protected $table = 'traited_cache_test_models';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    public static function redisModelCacheConfig(): array
    {
        return [
            'indexes' => ['role_id'],
            'ttl' => 3600,
        ];
    }
}
