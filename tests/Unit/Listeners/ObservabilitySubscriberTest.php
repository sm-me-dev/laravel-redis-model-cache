<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Tests\Unit\Listeners;

use Mockery;
use SmmE\RedisModelCache\Events\CacheHit;
use SmmE\RedisModelCache\Events\CacheMiss;
use SmmE\RedisModelCache\Events\CacheOperationFailed;
use SmmE\RedisModelCache\Events\CacheWrite;
use SmmE\RedisModelCache\Events\ModelCacheInvalidated;
use SmmE\RedisModelCache\Events\QueryExecuted;
use SmmE\RedisModelCache\Events\RedisConnectionFailed;
use SmmE\RedisModelCache\Listeners\ObservabilitySubscriber;
use SmmE\RedisModelCache\Support\Observability;
use SmmE\RedisModelCache\Tests\TestCase;

class ObservabilitySubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_cache_hit_records_hit_and_latency(): void
    {
        $observability = Mockery::mock(Observability::class);
        $observability->shouldReceive('recordHit')->once();
        $observability->shouldReceive('recordLatency')->with(12.5)->once();

        $subscriber = new ObservabilitySubscriber($observability);
        $subscriber->handleCacheHit(new CacheHit(
            modelClass: 'App\Models\User',
            query: ['role_id' => 1],
            resultCount: 5,
            executionTime: 12.5,
        ));

        $this->addToAssertionCount(1);
    }

    public function test_handle_cache_miss_records_miss_and_latency(): void
    {
        $observability = Mockery::mock(Observability::class);
        $observability->shouldReceive('recordMiss')->once();
        $observability->shouldReceive('recordLatency')->with(150.3)->once();

        $subscriber = new ObservabilitySubscriber($observability);
        $subscriber->handleCacheMiss(new CacheMiss(
            modelClass: 'App\Models\User',
            query: ['status' => 'active'],
            stampedeProtectionUsed: true,
            executionTime: 150.3,
        ));

        $this->addToAssertionCount(1);
    }

    public function test_handle_query_executed_records_pipeline_size_for_remember_all(): void
    {
        $observability = Mockery::mock(Observability::class);
        $observability->shouldReceive('recordPipelineSize')->with(50)->once();

        $subscriber = new ObservabilitySubscriber($observability);
        $subscriber->handleQueryExecuted(new QueryExecuted(
            modelClass: 'App\Models\User',
            operation: 'rememberAll',
            parameters: [],
            commandCount: 50,
            executionTime: 200.0,
            resultCount: 100,
        ));

        $this->addToAssertionCount(1);
    }

    public function test_handle_query_executed_ignores_non_bulk_operations(): void
    {
        $observability = Mockery::mock(Observability::class);
        $observability->shouldNotReceive('recordPipelineSize');

        $subscriber = new ObservabilitySubscriber($observability);
        $subscriber->handleQueryExecuted(new QueryExecuted(
            modelClass: 'App\Models\User',
            operation: 'where',
            parameters: [],
            commandCount: 2,
            executionTime: 5.0,
            resultCount: 10,
        ));

        $this->addToAssertionCount(1);
    }

    public function test_handle_cache_write_records_write(): void
    {
        $observability = Mockery::mock(Observability::class);
        $observability->shouldReceive('recordWrite')->once();

        $subscriber = new ObservabilitySubscriber($observability);
        $subscriber->handleCacheWrite(new CacheWrite(
            modelClass: 'App\Models\User',
            operation: 'storeMany',
            modelIds: [1, 2],
            executionTime: 5.0,
            modelCount: 2,
        ));

        $this->addToAssertionCount(1);
    }

    public function test_handle_model_cache_invalidated_records_invalidation(): void
    {
        $observability = Mockery::mock(Observability::class);
        $observability->shouldReceive('recordInvalidation')->once();

        $subscriber = new ObservabilitySubscriber($observability);
        $subscriber->handleModelCacheInvalidated(new ModelCacheInvalidated(
            modelClass: 'App\Models\User',
            modelId: 1,
            event: 'saved',
            timestamp: microtime(true),
        ));

        $this->addToAssertionCount(1);
    }

    public function test_handle_redis_connection_failed_records_failure(): void
    {
        $observability = Mockery::mock(Observability::class);
        $observability->shouldReceive('recordFailure')->once();

        $subscriber = new ObservabilitySubscriber($observability);
        $subscriber->handleRedisConnectionFailed(new RedisConnectionFailed(
            operation: 'hget',
            message: 'Connection refused',
        ));

        $this->addToAssertionCount(1);
    }

    public function test_handle_cache_operation_failed_records_failure(): void
    {
        $observability = Mockery::mock(Observability::class);
        $observability->shouldReceive('recordFailure')->once();

        $subscriber = new ObservabilitySubscriber($observability);
        $subscriber->handleCacheOperationFailed(new CacheOperationFailed(
            operation: 'where',
            message: 'Failed',
        ));

        $this->addToAssertionCount(1);
    }

    public function test_subscribe_returns_complete_event_mapping(): void
    {
        $observability = Mockery::mock(Observability::class);
        $subscriber = new ObservabilitySubscriber($observability);

        $map = $subscriber->subscribe();

        $expected = [
            CacheHit::class => 'handleCacheHit',
            CacheMiss::class => 'handleCacheMiss',
            QueryExecuted::class => 'handleQueryExecuted',
            CacheWrite::class => 'handleCacheWrite',
            ModelCacheInvalidated::class => 'handleModelCacheInvalidated',
            RedisConnectionFailed::class => 'handleRedisConnectionFailed',
            CacheOperationFailed::class => 'handleCacheOperationFailed',
        ];

        $this->assertSame($expected, $map);
    }
}
