<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class CacheMetrics
{
    /**
     * @param  array{hits: int, misses: int, total_requests: int, hit_rate: ?float, miss_rate: ?float}  $requests
     * @param  array{version: string, used_memory: int, used_memory_peak: int, uptime_seconds: int, connected_clients: int, total_keys: int, expired_keys: int}  $redis
     * @param  array{p50: ?float, p95: ?float, p99: ?float, average: ?float, min: ?float, max: ?float, samples: int}  $latency
     * @param  array{min: ?int, max: ?int, average: ?float, median: ?float, samples: list<int>}  $pipelineDistribution
     * @param  array{count: int, keys_removed: int}  $staleCleanup
     */
    public function __construct(
        public readonly array $requests,
        public readonly array $redis,
        public readonly array $latency,
        public readonly array $pipelineDistribution,
        public readonly array $staleCleanup,
        public readonly int $lockContention,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'requests' => $this->requests,
            'redis' => $this->redis,
            'latency' => $this->latency,
            'pipeline_distribution' => $this->pipelineDistribution,
            'stale_cleanup' => $this->staleCleanup,
            'lock_contention' => $this->lockContention,
        ];
    }
}
