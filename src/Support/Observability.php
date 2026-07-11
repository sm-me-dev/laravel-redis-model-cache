<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class Observability
{
    /**
     * Maximum number of latency samples retained in the ring buffer.
     * Must be a positive integer.
     */
    private const MAX_LATENCY = 1000;

    /**
     * Maximum number of pipeline-size samples retained in the ring buffer.
     * Must be a positive integer.
     */
    private const MAX_PIPELINE = 1000;

    /**
     * When any unbounded counter reaches this threshold all counters are
     * halved to prevent integer overflow while preserving hit/miss ratios.
     *
     * Set to PHP_INT_MAX >> 2 (approx 2.3e18 on 64-bit, 5.3e8 on 32-bit).
     * In practice this is a safety net — under normal web traffic it would
     * take centuries to reach.
     */
    private const COUNTER_NORMALIZE_THRESHOLD = PHP_INT_MAX >> 2;

    private int $hits = 0;

    private int $misses = 0;

    /** @var array<int, float> Ring buffer for latency samples */
    private array $latencySamples = [];

    /**
     * Monotonic write counter for the latency ring buffer.
     * Always incremented via writeLatencySlot() which bounds the slot index.
     */
    private int $latIdx = 0;

    /** @var array<int, int> Ring buffer for pipeline sizes */
    private array $pipelineSizes = [];

    /**
     * Monotonic write counter for the pipeline-size ring buffer.
     * Always incremented via writePipelineSlot() which bounds the slot index.
     */
    private int $pipeIdx = 0;

    private int $staleCleanupCount = 0;

    private int $lockContentionCount = 0;

    private int $staleCleanupKeysRemoved = 0;

    public function recordHit(): void
    {
        $this->normalizeCounters();
        $this->hits++;
    }

    public function recordMiss(): void
    {
        $this->normalizeCounters();
        $this->misses++;
    }

    /**
     * Record a latency sample (ms) in the overflow-safe ring buffer.
     *
     * The active slot is always in [0, MAX_LATENCY - 1].  The monotonic
     * counter `$latIdx` tracks how many samples have been written in total
     * but the array index is derived via modulo, so the counter can never
     * grow beyond PHP_INT_MAX without causing incorrect slot addressing.
     *
     * When the counter itself approaches PHP_INT_MAX (extremely unlikely in
     * practice) we reset it to its current modulo value so future writes
     * continue to target the correct slot without skipping or wrapping
     * unexpectedly.
     */
    public function recordLatency(float $milliseconds): void
    {
        $capacity = self::MAX_LATENCY;
        $slot = $this->latIdx % $capacity;
        $this->latencySamples[$slot] = $milliseconds;
        $this->latIdx = $this->nextCounter($this->latIdx, $capacity);
    }

    /**
     * Record a pipeline-size sample in the overflow-safe ring buffer.
     *
     * @see recordLatency() for the overflow-safety rationale.
     */
    public function recordPipelineSize(int $size): void
    {
        $capacity = self::MAX_PIPELINE;
        $slot = $this->pipeIdx % $capacity;
        $this->pipelineSizes[$slot] = $size;
        $this->pipeIdx = $this->nextCounter($this->pipeIdx, $capacity);
    }

    public function recordStaleCleanup(int $keysRemoved = 0): void
    {
        $this->normalizeCounters();
        $this->staleCleanupCount++;
        $this->staleCleanupKeysRemoved += $keysRemoved;
    }

    public function recordLockContention(): void
    {
        $this->normalizeCounters();
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
     * The counter `$idx` is the monotonic write count (not a raw slot index).
     * The slot index used for the next write is always `$idx % $max`.
     *
     * When $idx <= $max all slots [0..$idx-1] were written in order.
     * When $idx > $max the oldest slot is at position ($idx % $max) and
     * the buffer has wrapped; we read from that position to the end, then
     * from 0 to that position.
     *
     * @template T of int|float
     *
     * @param  array<int, T>  $buffer
     * @return list<T>
     */
    private function flattenRingBuffer(array $buffer, int $idx, int $max): array
    {
        // Guard against misconfigured capacity
        if ($max <= 0) {
            return [];
        }

        $count = min($idx, $max);

        if ($count === 0) {
            return [];
        }

        $result = [];

        if ($idx <= $max) {
            // Buffer has not yet wrapped; slots 0..$count-1 are in insertion order.
            for ($i = 0; $i < $count; $i++) {
                $result[] = $buffer[$i];
            }
        } else {
            // Buffer has wrapped; oldest entry is at slot ($idx % $max).
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

    /**
     * Total number of latency samples written (monotonic; bounded against overflow).
     */
    public function latencyWriteCount(): int
    {
        return $this->latIdx;
    }

    /**
     * Total number of pipeline-size samples written (monotonic; bounded against overflow).
     */
    public function pipelineWriteCount(): int
    {
        return $this->pipeIdx;
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

    /**
     * Halve all unbounded counters when any one reaches the normalisation
     * threshold, preventing integer overflow under sustained production load.
     *
     * Bitwise right-shift division preserves the relative ratios between
     * counters (hit rate, miss rate, etc.) and uses O(1) overhead per write.
     *
     * The threshold is PHP_INT_MAX >> 2 which on 64-bit is ~2.3 quintillion
     * — far beyond any realistic counter value.  This guard exists purely as
     * a safety net against pathological or decades-long uptime scenarios.
     */
    private function normalizeCounters(): void
    {
        if ($this->hits < self::COUNTER_NORMALIZE_THRESHOLD
            && $this->misses < self::COUNTER_NORMALIZE_THRESHOLD
            && $this->staleCleanupCount < self::COUNTER_NORMALIZE_THRESHOLD
            && $this->lockContentionCount < self::COUNTER_NORMALIZE_THRESHOLD
            && $this->staleCleanupKeysRemoved < self::COUNTER_NORMALIZE_THRESHOLD
        ) {
            return;
        }

        $this->hits >>= 1;
        $this->misses >>= 1;
        $this->staleCleanupCount >>= 1;
        $this->lockContentionCount >>= 1;
        $this->staleCleanupKeysRemoved >>= 1;
    }

    /**
     * Advance a monotonic write counter and protect against integer overflow.
     *
     * Under sustained long-lived production load the counter would eventually
     * approach PHP_INT_MAX.  When it gets within one capacity-sized chunk of
     * the maximum we reset it to its current modulo value so subsequent writes
     * continue targeting the correct slot without any discontinuity.
     *
     * The reset point is "capacity away from PHP_INT_MAX" which in practice
     * means this branch is never taken for normal workloads; it exists purely
     * as a safety net.
     */
    private function nextCounter(int $current, int $capacity): int
    {
        $next = $current + 1;

        // Overflow guard: if next is within one capacity of PHP_INT_MAX,
        // collapse to the current modulo position so ring semantics are preserved.
        if ($next > PHP_INT_MAX - $capacity) {
            return $next % $capacity;
        }

        return $next;
    }
}
