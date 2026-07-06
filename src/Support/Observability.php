<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class Observability
{
    private const MAX_LATENCY = 1000;

    private const MAX_PIPELINE = 1000;

    private int $hits = 0;

    private int $misses = 0;

    /** @var array<int, float> Ring buffer for latency samples */
    private array $latencySamples = [];

    private int $latIdx = 0;

    /** @var array<int, int> Ring buffer for pipeline sizes */
    private array $pipelineSizes = [];

    private int $pipeIdx = 0;

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
        $this->latencySamples[$this->latIdx % self::MAX_LATENCY] = $milliseconds;
        $this->latIdx++;
    }

    public function recordPipelineSize(int $size): void
    {
        $this->pipelineSizes[$this->pipeIdx % self::MAX_PIPELINE] = $size;
        $this->pipeIdx++;
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

    /**
     * Flatten a ring buffer into insertion-order linear array.
     *
     * @template T of int|float
     *
     * @param  array<int, T>  $buffer
     * @return list<T>
     */
    private function flattenRingBuffer(array $buffer, int $idx, int $max): array
    {
        $count = min($idx, $max);

        if ($count === 0) {
            return [];
        }

        $result = [];

        if ($idx <= $max) {
            for ($i = 0; $i < $count; $i++) {
                $result[] = $buffer[$i];
            }
        } else {
            $start = $idx % $max;
            for ($i = $start; $i < $max; $i++) {
                $result[] = $buffer[$i];
            }
            for ($i = 0; $i < $start; $i++) {
                $result[] = $buffer[$i];
            }
        }

        return $result;
    }

    public function latencyPercentile(int $percentile): ?float
    {
        $samples = $this->flattenRingBuffer($this->latencySamples, $this->latIdx, self::MAX_LATENCY);

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
        $samples = $this->flattenRingBuffer($this->latencySamples, $this->latIdx, self::MAX_LATENCY);

        if ($samples === []) {
            return null;
        }

        return round(array_sum($samples) / count($samples), 2);
    }

    public function maxLatency(): ?float
    {
        $samples = $this->flattenRingBuffer($this->latencySamples, $this->latIdx, self::MAX_LATENCY);

        if ($samples === []) {
            return null;
        }

        return round(max($samples), 2);
    }

    public function minLatency(): ?float
    {
        $samples = $this->flattenRingBuffer($this->latencySamples, $this->latIdx, self::MAX_LATENCY);

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
        return $this->flattenRingBuffer($this->latencySamples, $this->latIdx, self::MAX_LATENCY);
    }

    /**
     * @return array{min: ?float, max: ?float, average: ?float, median: ?float, samples: list<int>}
     */
    public function pipelineSizeDistribution(): array
    {
        $sizes = $this->flattenRingBuffer($this->pipelineSizes, $this->pipeIdx, self::MAX_PIPELINE);

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
        $this->latIdx = 0;
        $this->pipelineSizes = [];
        $this->pipeIdx = 0;
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
                'samples' => min($this->latIdx, self::MAX_LATENCY),
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
