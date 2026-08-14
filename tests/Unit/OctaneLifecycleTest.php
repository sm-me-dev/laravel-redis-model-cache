<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Tests\Unit;

use Mockery;
use SmmE\RedisModelCache\Events\CacheHit;
use SmmE\RedisModelCache\Events\CacheMiss;
use SmmE\RedisModelCache\Events\CacheOperationFailed;
use SmmE\RedisModelCache\Events\CacheWrite;
use SmmE\RedisModelCache\Events\ModelCacheInvalidated;
use SmmE\RedisModelCache\Events\QueryExecuted;
use SmmE\RedisModelCache\Events\RedisConnectionFailed;
use SmmE\RedisModelCache\Listeners\ObservabilitySubscriber;
use SmmE\RedisModelCache\RedisModelService;
use SmmE\RedisModelCache\Support\Observability;
use SmmE\RedisModelCache\Tests\Fixtures\DummyModel;
use SmmE\RedisModelCache\Tests\TestCase;

class OctaneLifecycleTest extends TestCase
{
    private RedisModelService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'sorted' => [],
            'ttl' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_observability_reset_clears_all_counters(): void
    {
        $observability = app(Observability::class);

        $observability->recordHit();
        $observability->recordMiss();
        $observability->recordLatency(5.0);
        $observability->recordPipelineSize(10);
        $observability->recordStaleCleanup(3);
        $observability->recordLockContention();

        $observability->reset();

        $snapshot = $observability->snapshot();

        $this->assertSame(0, $snapshot['hits']);
        $this->assertSame(0, $snapshot['misses']);
        $this->assertSame(0, $snapshot['total_requests']);
        $this->assertNull($snapshot['hit_rate']);
        $this->assertNull($snapshot['miss_rate']);
        $this->assertSame(0, $snapshot['latency']['samples']);
        $this->assertSame([], $snapshot['pipeline_size']['samples']);
        $this->assertSame(0, $snapshot['stale_cleanup']['count']);
        $this->assertSame(0, $snapshot['stale_cleanup']['keys_removed']);
        $this->assertSame(0, $snapshot['lock_contention']);
    }

    public function test_observability_reset_is_idempotent(): void
    {
        $observability = app(Observability::class);

        $observability->reset();
        $observability->reset();
        $observability->reset();

        $snapshot = $observability->snapshot();

        $this->assertSame(0, $snapshot['hits']);
        $this->assertSame(0, $snapshot['total_requests']);
    }

    public function test_reset_after_single_hit_keeps_empty_state(): void
    {
        $observability = app(Observability::class);

        $observability->recordHit();
        $observability->reset();

        $this->assertSame(0, $observability->hits());
        $this->assertSame(0, $observability->misses());
        $this->assertSame(0, $observability->latencyWriteCount());
        $this->assertSame(0, $observability->pipelineWriteCount());
    }

    public function test_new_instance_has_clean_state(): void
    {
        $observability = new Observability;

        $snapshot = $observability->snapshot();

        $this->assertSame(0, $snapshot['hits']);
        $this->assertSame(0, $snapshot['misses']);
        $this->assertSame(0, $snapshot['total_requests']);
        $this->assertNull($snapshot['hit_rate']);
        $this->assertNull($snapshot['miss_rate']);
        $this->assertEmpty($snapshot['latency']['samples']);
        $this->assertEmpty($snapshot['pipeline_size']['samples']);
        $this->assertSame(0, $snapshot['stale_cleanup']['count']);
        $this->assertSame(0, $snapshot['stale_cleanup']['keys_removed']);
        $this->assertSame(0, $snapshot['lock_contention']);
    }

    public function test_observability_shared_across_service_resets_correctly(): void
    {
        $observability = app(Observability::class);

        $observability->recordHit();
        $observability->recordHit();
        $observability->recordMiss();

        $this->assertSame(3, $observability->snapshot()['total_requests']);

        $observability->reset();

        $this->assertSame(0, $observability->hits());
        $this->assertSame(0, $observability->misses());

        $observability->recordHit();
        $this->assertSame(1, $observability->hits());
    }

    public function test_event_subscriber_mapping_stability(): void
    {
        $subscriber = app(ObservabilitySubscriber::class);
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
