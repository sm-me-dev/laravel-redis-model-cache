<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Listeners;

use Mockery;
use Sm_mE\RedisModelCache\Events\CacheHit;
use Sm_mE\RedisModelCache\Events\CacheMiss;
use Sm_mE\RedisModelCache\Events\QueryExecuted;
use Sm_mE\RedisModelCache\Listeners\ObservabilitySubscriber;
use Sm_mE\RedisModelCache\Support\Observability;
use Sm_mE\RedisModelCache\Tests\TestCase;

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

    public function test_subscribe_returns_event_mapping(): void
    {
        $observability = Mockery::mock(Observability::class);
        $subscriber = new ObservabilitySubscriber($observability);

        $map = $subscriber->subscribe();

        $this->assertArrayHasKey(CacheHit::class, $map);
        $this->assertArrayHasKey(CacheMiss::class, $map);
        $this->assertArrayHasKey(QueryExecuted::class, $map);
        $this->assertSame('handleCacheHit', $map[CacheHit::class]);
        $this->assertSame('handleCacheMiss', $map[CacheMiss::class]);
        $this->assertSame('handleQueryExecuted', $map[QueryExecuted::class]);
    }
}
