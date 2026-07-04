<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class Observability
{
    private int $hits = 0;

    private int $misses = 0;

    /** @var array<int, float> */
    private array $latencySamples = [];

    /** @var array<int, int> */
    private array $pipelineSizes = [];

    private int $staleCleanupCount = 0;

    private int $lockContentionCount = 0;

    private int $staleCleanupKeysRemoved = 0;

    public function recordHit(): void
    {
        $this->hits++;
    }

    public function recordMiss(): void
    {
        $this->misses++;
    }

    public function recordLatency(float $milliseconds): void
    {
        $this->latencySamples[] = $milliseconds;
    }

    public function recordPipelineSize(int $size): void
    {
        $this->pipelineSizes[] = $size;
    }

    public function recordStaleCleanup(int $keysRemoved = 0): void
    {
        $this->staleCleanupCount++;
        $this->staleCleanupKeysRemoved += $keysRemoved;
    }

    public function recordLockContention(): void
    {
        $this->lockContentionCount++;
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function misses(): int
    {
        return $this->misses;
    }

    public function totalRequests(): int
    {
        return $this->hits + $this->misses;
    }

    public function hitRate(): ?float
    {
        $total = $this->totalRequests();

        if ($total === 0) {
            return null;
        }

        return round(($this->hits / $total) * 100, 2);
    }

    public function missRate(): ?float
    {
        $total = $this->totalRequests();

        if ($total === 0) {
            return null;
        }

        return round(($this->misses / $total) * 100, 2);
    }

    public function latencyPercentile(int $percentile): ?float
    {
        $samples = $this->latencySamples;

        if ($samples === []) {
            return null;
        }

        sort($samples);

        $index = (int) ceil(($percentile / 100) * count($samples)) - 1;
        $index = max(0, min($index, count($samples) - 1));

        return round($samples[$index], 2);
    }

    public function averageLatency(): ?float
    {
        $samples = $this->latencySamples;

        if ($samples === []) {
            return null;
        }

        return round(array_sum($samples) / count($samples), 2);
    }

    public function maxLatency(): ?float
    {
        $samples = $this->latencySamples;

        if ($samples === []) {
            return null;
        }

        return round(max($samples), 2);
    }

    public function minLatency(): ?float
    {
        $samples = $this->latencySamples;

        if ($samples === []) {
            return null;
        }

        return round(min($samples), 2);
    }

    /**
     * @return array<int, float>
     */
    public function latencySamples(): array
    {
        return $this->latencySamples;
    }

    /**
     * @return array{min: ?float, max: ?float, average: ?float, median: ?float, samples: list<int>}
     */
    public function pipelineSizeDistribution(): array
    {
        $sizes = $this->pipelineSizes;

        if ($sizes === []) {
            return [
                'min' => null,
                'max' => null,
                'average' => null,
                'median' => null,
                'samples' => [],
            ];
        }

        sort($sizes);
        $count = count($sizes);
        $mid = (int) floor($count / 2);
        $median = $count % 2 === 0
            ? ($sizes[$mid - 1] + $sizes[$mid]) / 2
            : $sizes[$mid];

        return [
            'min' => min($sizes),
            'max' => max($sizes),
            'average' => round(array_sum($sizes) / $count, 1),
            'median' => $median,
            'samples' => $sizes,
        ];
    }

    public function staleCleanupCount(): int
    {
        return $this->staleCleanupCount;
    }

    public function staleCleanupKeysRemoved(): int
    {
        return $this->staleCleanupKeysRemoved;
    }

    public function lockContentionCount(): int
    {
        return $this->lockContentionCount;
    }

    public function reset(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->latencySamples = [];
        $this->pipelineSizes = [];
        $this->staleCleanupCount = 0;
        $this->lockContentionCount = 0;
        $this->staleCleanupKeysRemoved = 0;
    }

    /**
     * @return array{hits: int, misses: int, total_requests: int, hit_rate: ?float, miss_rate: ?float, latency: array{p50: ?float, p95: ?float, p99: ?float, average: ?float, min: ?float, max: ?float, samples: int}, pipeline_size: array{min: ?float, max: ?float, average: ?float, median: ?float, samples: list<int>}, stale_cleanup: array{count: int, keys_removed: int}, lock_contention: int}
     */
    public function snapshot(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total_requests' => $this->totalRequests(),
            'hit_rate' => $this->hitRate(),
            'miss_rate' => $this->missRate(),
            'latency' => [
                'p50' => $this->latencyPercentile(50),
                'p95' => $this->latencyPercentile(95),
                'p99' => $this->latencyPercentile(99),
                'average' => $this->averageLatency(),
                'min' => $this->minLatency(),
                'max' => $this->maxLatency(),
                'samples' => count($this->latencySamples),
            ],
            'pipeline_size' => $this->pipelineSizeDistribution(),
            'stale_cleanup' => [
                'count' => $this->staleCleanupCount,
                'keys_removed' => $this->staleCleanupKeysRemoved,
            ],
            'lock_contention' => $this->lockContentionCount,
        ];
    }
}
