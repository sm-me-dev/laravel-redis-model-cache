<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Support;

use Sm_mE\RedisModelCache\Support\CacheMetrics;
use Sm_mE\RedisModelCache\Tests\TestCase;

class CacheMetricsTest extends TestCase
{
    public function test_constructor_sets_properties(): void
    {
        $metrics = new CacheMetrics(
            requests: ['hits' => 10, 'misses' => 2, 'total_requests' => 12, 'hit_rate' => 83.33, 'miss_rate' => 16.67],
            redis: ['version' => '7.2', 'used_memory' => 1024, 'used_memory_peak' => 2048, 'uptime_seconds' => 3600, 'connected_clients' => 5, 'total_keys' => 100, 'expired_keys' => 10],
            latency: ['p50' => 1.0, 'p95' => 5.0, 'p99' => 10.0, 'average' => 2.0, 'min' => 0.5, 'max' => 15.0, 'samples' => 12],
            pipelineDistribution: ['min' => 1, 'max' => 50, 'average' => 10.5, 'median' => 8, 'samples' => [1, 5, 10]],
            staleCleanup: ['count' => 2, 'keys_removed' => 10],
            lockContention: 0,
        );

        $this->assertSame(10, $metrics->requests['hits']);
        $this->assertSame('7.2', $metrics->redis['version']);
        $this->assertSame(1.0, $metrics->latency['p50']);
        $this->assertSame(1, $metrics->pipelineDistribution['min']);
        $this->assertSame(2, $metrics->staleCleanup['count']);
        $this->assertSame(0, $metrics->lockContention);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $metrics = new CacheMetrics(
            requests: ['hits' => 0, 'misses' => 0, 'total_requests' => 0, 'hit_rate' => null, 'miss_rate' => null],
            redis: ['version' => 'N/A', 'used_memory' => 0, 'used_memory_peak' => 0, 'uptime_seconds' => 0, 'connected_clients' => 0, 'total_keys' => 0, 'expired_keys' => 0],
            latency: ['p50' => null, 'p95' => null, 'p99' => null, 'average' => null, 'min' => null, 'max' => null, 'samples' => 0],
            pipelineDistribution: ['min' => null, 'max' => null, 'average' => null, 'median' => null, 'samples' => []],
            staleCleanup: ['count' => 0, 'keys_removed' => 0],
            lockContention: 0,
        );

        $array = $metrics->toArray();

        $this->assertArrayHasKey('requests', $array);
        $this->assertArrayHasKey('redis', $array);
        $this->assertArrayHasKey('latency', $array);
        $this->assertArrayHasKey('pipeline_distribution', $array);
        $this->assertArrayHasKey('stale_cleanup', $array);
        $this->assertArrayHasKey('lock_contention', $array);
    }
}
