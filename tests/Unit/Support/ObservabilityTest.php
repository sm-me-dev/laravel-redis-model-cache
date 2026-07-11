<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Support;

use ReflectionClass;
use Sm_mE\RedisModelCache\Support\Observability;
use Sm_mE\RedisModelCache\Tests\TestCase;

class ObservabilityTest extends TestCase
{
    private Observability $observability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->observability = new Observability;
    }

    // ── Ring buffer wraparound ───────────────────────────────────────────

    public function test_ring_buffer_keeps_only_newest_samples_after_wraparound(): void
    {
        $total = 2500;

        for ($i = 0; $i < $total; $i++) {
            $this->observability->recordLatency((float) $i);
        }

        $samples = $this->observability->latencySamples();
        $this->assertCount(1000, $samples, 'Only 1000 slots should be retained');

        $this->assertSame(1500.0, $samples[0], 'Oldest retained sample should be at index 1500');
        $this->assertSame(2499.0, $samples[999], 'Newest sample should be at index 2499');
    }

    public function test_pipeline_ring_buffer_rotation(): void
    {
        for ($i = 0; $i < 1200; $i++) {
            $this->observability->recordPipelineSize($i);
        }

        $dist = $this->observability->pipelineSizeDistribution();
        $this->assertCount(1000, $dist['samples'], 'Only 1000 pipeline samples retained');
        $this->assertSame(200, $dist['min'], 'Oldest retained sample should be 200');
        $this->assertSame(1199, $dist['max'], 'Newest sample should be 1199');
    }

    // ── Counter normalization ────────────────────────────────────────────

    public function test_counter_normalization_on_overflow(): void
    {
        $ref = new ReflectionClass($this->observability);
        $threshold = $ref->getConstant('COUNTER_NORMALIZE_THRESHOLD');

        $hitsProp = $ref->getProperty('hits');
        $hitsProp->setAccessible(true);
        $hitsProp->setValue($this->observability, $threshold);

        $this->observability->recordHit();

        $this->assertSame(
            (int) floor(($threshold + 1) / 2),
            $this->observability->hits(),
            'Hits should be halved after normalization',
        );
    }

    public function test_counter_normalization_preserves_ratios(): void
    {
        $ref = new ReflectionClass($this->observability);
        $threshold = $ref->getConstant('COUNTER_NORMALIZE_THRESHOLD');

        $hitsProp = $ref->getProperty('hits');
        $hitsProp->setAccessible(true);
        $hitsProp->setValue($this->observability, $threshold - 50);

        $missesProp = $ref->getProperty('misses');
        $missesProp->setAccessible(true);
        $missesProp->setValue($this->observability, $threshold - 150);

        $preHitRate = $this->observability->hitRate();

        $this->observability->recordHit();
        $this->observability->recordMiss();

        $postHitRate = $this->observability->hitRate();

        $this->assertNotNull($preHitRate);
        $this->assertNotNull($postHitRate);
        $this->assertEqualsWithDelta($preHitRate, $postHitRate, 0.01, 'Hit rate should be preserved after normalization');
    }

    public function test_all_counters_normalized_together(): void
    {
        $ref = new ReflectionClass($this->observability);
        $threshold = $ref->getConstant('COUNTER_NORMALIZE_THRESHOLD');

        foreach (['hits', 'misses', 'staleCleanupCount', 'lockContentionCount', 'staleCleanupKeysRemoved'] as $propName) {
            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $prop->setValue($this->observability, $threshold);
        }

        $this->observability->recordHit();

        foreach (['hits', 'misses', 'staleCleanupCount', 'lockContentionCount', 'staleCleanupKeysRemoved'] as $propName) {
            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $this->assertLessThan(
                $threshold,
                $prop->getValue($this->observability),
                "$propName should be normalized below threshold",
            );
        }
    }

    public function test_normalize_noop_below_threshold(): void
    {
        $this->observability->recordHit();
        $this->observability->recordMiss();
        $this->observability->recordStaleCleanup(5);
        $this->observability->recordLockContention();

        $this->assertSame(1, $this->observability->hits());
        $this->assertSame(1, $this->observability->misses());
        $this->assertSame(1, $this->observability->staleCleanupCount());
        $this->assertSame(5, $this->observability->staleCleanupKeysRemoved());
        $this->assertSame(1, $this->observability->lockContentionCount());
    }

    // ── Edge: empty states ───────────────────────────────────────────────

    public function test_latency_percentile_returns_null_when_no_samples(): void
    {
        $this->assertNull($this->observability->latencyPercentile(50));
    }

    public function test_average_latency_returns_null_when_no_samples(): void
    {
        $this->assertNull($this->observability->averageLatency());
    }

    public function test_hit_rate_returns_null_when_no_requests(): void
    {
        $this->assertNull($this->observability->hitRate());
    }

    public function test_snapshot_returns_expected_structure(): void
    {
        $this->observability->recordHit();
        $this->observability->recordMiss();
        $this->observability->recordLatency(5.0);
        $this->observability->recordPipelineSize(10);
        $this->observability->recordStaleCleanup(3);
        $this->observability->recordLockContention();

        $snapshot = $this->observability->snapshot();

        $this->assertSame(1, $snapshot['hits']);
        $this->assertSame(1, $snapshot['misses']);
        $this->assertSame(2, $snapshot['total_requests']);
        $this->assertSame(50.0, $snapshot['hit_rate']);
        $this->assertSame(50.0, $snapshot['miss_rate']);
        $this->assertArrayHasKey('latency', $snapshot);
        $this->assertArrayHasKey('pipeline_size', $snapshot);
        $this->assertSame(1, $snapshot['stale_cleanup']['count']);
        $this->assertSame(3, $snapshot['stale_cleanup']['keys_removed']);
        $this->assertSame(1, $snapshot['lock_contention']);
    }

    public function test_snapshot_latency_samples_count_capped_at_max(): void
    {
        for ($i = 0; $i < 5000; $i++) {
            $this->observability->recordLatency((float) $i);
        }

        $snapshot = $this->observability->snapshot();
        $this->assertSame(1000, $snapshot['latency']['samples']);
    }

    // ── Latency write count ──────────────────────────────────────────────

    public function test_latency_write_count_is_monotonic(): void
    {
        for ($i = 1; $i <= 1500; $i++) {
            $this->observability->recordLatency((float) $i);
            $this->assertSame($i, $this->observability->latencyWriteCount());
        }
    }

    // ── Reset ────────────────────────────────────────────────────────────

    public function test_reset_zeros_all_counters_and_clears_buffers(): void
    {
        $this->observability->recordHit();
        $this->observability->recordMiss();
        $this->observability->recordLatency(1.0);
        $this->observability->recordPipelineSize(5);
        $this->observability->recordStaleCleanup(10);
        $this->observability->recordLockContention();

        $this->observability->reset();

        $this->assertSame(0, $this->observability->hits());
        $this->assertSame(0, $this->observability->misses());
        $this->assertSame([], $this->observability->latencySamples());
        $this->assertSame([], $this->observability->pipelineSizeDistribution()['samples']);
        $this->assertSame(0, $this->observability->staleCleanupCount());
        $this->assertSame(0, $this->observability->staleCleanupKeysRemoved());
        $this->assertSame(0, $this->observability->lockContentionCount());
        $this->assertSame(0, $this->observability->latencyWriteCount());
        $this->assertSame(0, $this->observability->pipelineWriteCount());
    }
}
