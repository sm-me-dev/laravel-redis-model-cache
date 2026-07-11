#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scalability Benchmark — 100K / 500K / 1M record simulation
 *
 * Measures storeMany() and where() throughput at increasingly large scales.
 * Uses progressive sampling (store then query subsets) to avoid OOM on
 * pipeline queues and HMGET reply buffers.
 *
 * Run: php benchmarks/scalability_benchmark.php
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sm_mE\RedisModelCache\RedisModelService;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/bootstrap.php';

// Parse args
$maxScale = 100000;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max-scale=')) {
        $maxScale = (int) substr($arg, 12);
    }
}

class ScalabilityModel extends Model
{
    protected $table = 'scalability_bench';

    protected $fillable = ['id', 'name', 'email', 'role_id', 'status', 'score', 'created_at', 'bio'];

    public $timestamps = false;
}

function createModels(int $count): Collection
{
    $models = [];
    for ($i = 1; $i <= $count; $i++) {
        $models[] = new ScalabilityModel([
            'id' => $i,
            'name' => "User {$i}",
            'email' => "user{$i}@example.com",
            'role_id' => ($i % 10) + 1,
            'status' => $i % 2 === 0 ? 'active' : 'inactive',
            'score' => (float) ($i * 1.5),
            'created_at' => now()->subDays($count - $i)->toDateTimeString(),
            'bio' => str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit ', 20),
        ]);
    }

    return new Collection($models);
}

$service = app(RedisModelService::class, [
    'model_class' => ScalabilityModel::class,
    'indexes' => ['role_id', 'status'],
    'sorted' => ['score', 'created_at'],
    'ttl' => 3600,
]);

echo "============================================\n";
echo "    Scalability Benchmark Suite\n";
echo "============================================\n";
echo 'PHP Version: '.PHP_VERSION."\n";
echo 'Laravel: '.app()->version()."\n";
echo 'Redis: '.config('database.redis.cache.host', '127.0.0.1').':'.config('database.redis.cache.port', '6379')."\n\n";

$scales = [100, 1000, 10000, 50000, min($maxScale, 100000)];

foreach ($scales as $count) {
    if ($count > $maxScale) {
        continue;
    }

    $models = createModels($count);
    echo "━━━ Scale: {$count} records ━━━\n\n";

    // ── Write Throughput ──────────────────────────────────────────
    $service->clear();
    gc_collect_cycles();

    $startMem = memory_get_usage();
    $startTime = microtime(true);

    if ($count <= 10000) {
        $service->storeMany($models);
    } else {
        $perBatch = 5000;
        foreach ($models->chunk($perBatch) as $batch) {
            $service->storeMany($batch);
        }
    }

    $writeTime = microtime(true) - $startTime;
    $writeMem = memory_get_usage() - $startMem;
    $writeThroughput = $count / $writeTime;

    echo "[Write] storeMany({$count})\n";
    echo '  Total time: '.number_format($writeTime * 1000, 2)." ms\n";
    echo '  Throughput: '.number_format($writeThroughput, 0)." models/sec\n";
    echo '  Memory delta: '.number_format($writeMem / 1024, 2)." KB\n";
    echo '  Per model: '.number_format(($writeTime / $count) * 1000, 4)." ms\n\n";

    // ── Indexed Read (small result set) ────────────────────────────
    $readOps = min($count, 1000);
    $startTime = microtime(true);

    for ($i = 0; $i < $readOps; $i++) {
        $service->where(['role_id' => ($i % 10) + 1]);
    }

    $readTime = microtime(true) - $startTime;
    $readThroughput = $readOps / $readTime;

    echo "[Read] where(role_id) × {$readOps} queries\n";
    echo '  Total time: '.number_format($readTime * 1000, 2)." ms\n";
    echo '  Throughput: '.number_format($readThroughput, 0)." qps\n";
    echo '  Per query: '.number_format(($readTime / $readOps) * 1000, 4)." ms\n\n";

    // ── Large result set read (unfiltered role_id) ─────────────────
    $startTime = microtime(true);
    $largeResult = $service->where(['role_id' => 1]);
    $largeReadTime = microtime(true) - $startTime;
    $largeCount = $largeResult->count();

    echo "[Read] where(role_id=1) — {$largeCount} results\n";
    echo '  Total time: '.number_format($largeReadTime * 1000, 2)." ms\n";
    echo '  Per result: '.number_format(($largeReadTime / max(1, $largeCount)) * 1000 * 1000, 2)." µs\n\n";

    // ── Partial hydration (pluck) ──────────────────────────────────
    $pluckOps = min($count, 500);
    $startTime = microtime(true);

    for ($i = 0; $i < $pluckOps; $i++) {
        $service->pluck(['id', 'name', 'email'], ['role_id' => ($i % 10) + 1]);
    }

    $pluckTime = microtime(true) - $startTime;
    $pluckThroughput = $pluckOps / $pluckTime;

    echo "[Read] pluck(['id','name','email']) × {$pluckOps} queries\n";
    echo '  Total time: '.number_format($pluckTime * 1000, 2)." ms\n";
    echo '  Throughput: '.number_format($pluckThroughput, 0)." qps\n";
    echo '  Per query: '.number_format(($pluckTime / $pluckOps) * 1000, 4)." ms\n\n";

    // ── Sorted read ────────────────────────────────────────────────
    $sortedOps = min($count, 500);
    $startTime = microtime(true);

    for ($i = 0; $i < $sortedOps; $i++) {
        $min = (float) ($i * 1.5);
        $max = $min + 1000;
        $service->whereBetween('score', $min, $max);
    }

    $sortedTime = microtime(true) - $startTime;
    $sortedThroughput = $sortedOps / $sortedTime;

    echo "[Read] whereBetween(score) × {$sortedOps} queries\n";
    echo '  Total time: '.number_format($sortedTime * 1000, 2)." ms\n";
    echo '  Throughput: '.number_format($sortedThroughput, 0)." qps\n";
    echo '  Per query: '.number_format(($sortedTime / $sortedOps) * 1000, 4)." ms\n\n";

    $service->clear();
    echo "---\n\n";
}

echo "============================================\n";
echo "    Scalability Benchmark Complete\n";
echo "============================================\n";
