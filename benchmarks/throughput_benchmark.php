#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Throughput Benchmark
 *
 * Measures write and read throughput at 1K, 10K, and 100K record scales.
 * Provides operations/sec metrics for storeMany(), where(), and mixed workloads.
 *
 * Run: php benchmarks/throughput_benchmark.php
 *      php benchmarks/throughput_benchmark.php --scale=1000
 *      php benchmarks/throughput_benchmark.php --scale=10000
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sm_mE\RedisModelCache\RedisModelService;

require __DIR__.'/../vendor/autoload.php';

require __DIR__.'/bootstrap.php';

// Parse args
$scale = 1000;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--scale=')) {
        $scale = (int) substr($arg, 8);
    }
}

$scales = [$scale];

class ThroughputModel extends Model
{
    protected $table = 'throughput_bench';

    protected $fillable = ['id', 'name', 'email', 'role_id', 'status', 'score', 'created_at'];

    public $timestamps = false;
}

function createModels(int $count): Collection
{
    $models = [];
    for ($i = 1; $i <= $count; $i++) {
        $models[] = new ThroughputModel([
            'id' => $i,
            'name' => "User {$i}",
            'email' => "user{$i}@example.com",
            'role_id' => ($i % 5) + 1,
            'status' => $i % 2 === 0 ? 'active' : 'inactive',
            'score' => (float) ($i * 1.5),
            'created_at' => now()->subDays($count - $i)->toDateTimeString(),
        ]);
    }

    return new Collection($models);
}

$service = app(RedisModelService::class, [
    'model_class' => ThroughputModel::class,
    'indexes' => ['role_id', 'status'],
    'sorted' => ['score', 'created_at'],
    'ttl' => 3600,
]);

echo "========================================\n";
echo "    Throughput Benchmark Suite\n";
echo "========================================\n";
echo 'PHP Version: '.PHP_VERSION."\n";
echo 'Laravel: '.app()->version()."\n";
echo 'Redis: '.config('database.redis.cache.host', '127.0.0.1').':'.config('database.redis.cache.port', '6379')."\n\n";

foreach ($scales as $count) {
    echo "━━━ Scale: {$count} records ━━━\n\n";

    // --- Write Throughput ---
    $models = createModels($count);

    $service->clear();

    $startTime = microtime(true);
    $startMem = memory_get_usage();

    $service->storeMany($models);

    $writeTime = microtime(true) - $startTime;
    $writeMem = memory_get_usage() - $startMem;
    $writeThroughput = $count / $writeTime;

    echo "[Write] storeMany({$count} models)\n";
    echo '  Total time: '.number_format($writeTime * 1000, 2)." ms\n";
    echo '  Throughput: '.number_format($writeThroughput, 0)." models/sec\n";
    echo '  Memory: '.number_format($writeMem / 1024, 2)." KB\n";
    echo '  Per model: '.number_format(($writeTime / $count) * 1000, 4)." ms\n\n";

    // --- Indexed Read Throughput (where) ---
    $readCount = min($count, 10000);
    $startTime = microtime(true);

    for ($i = 0; $i < $readCount; $i++) {
        $service->where(['role_id' => ($i % 5) + 1]);
    }

    $readTime = microtime(true) - $startTime;
    $readThroughput = $readCount / $readTime;

    echo "[Read] where(role_id) × {$readCount} queries\n";
    echo '  Total time: '.number_format($readTime * 1000, 2)." ms\n";
    echo '  Throughput: '.number_format($readThroughput, 0)." queries/sec\n";
    echo '  Per query: '.number_format(($readTime / $readCount) * 1000, 4)." ms\n\n";

    // --- Sorted Read Throughput (whereBetween) ---
    $sortedReadCount = min($count, 5000);
    $startTime = microtime(true);

    for ($i = 0; $i < $sortedReadCount; $i++) {
        $min = (float) ($i * 1.5);
        $max = $min + 100;
        $service->whereBetween('score', $min, $max);
    }

    $sortedReadTime = microtime(true) - $startTime;
    $sortedThroughput = $sortedReadCount / $sortedReadTime;

    echo "[Read] whereBetween(score) × {$sortedReadCount} queries\n";
    echo '  Total time: '.number_format($sortedReadTime * 1000, 2)." ms\n";
    echo '  Throughput: '.number_format($sortedThroughput, 0)." queries/sec\n";
    echo '  Per query: '.number_format(($sortedReadTime / $sortedReadCount) * 1000, 4)." ms\n\n";

    // --- Mixed Workload (50/50 read/write) ---
    $mixedOps = min($count, 2000);
    $startTime = microtime(true);

    for ($i = 0; $i < $mixedOps; $i++) {
        if ($i % 2 === 0) {
            $service->where(['status' => 'active']);
        } else {
            $model = new ThroughputModel([
                'id' => $count + $i + 1,
                'name' => "Mixed User {$i}",
                'email' => "mixed{$i}@example.com",
                'role_id' => 1,
                'status' => 'active',
                'score' => 100.0,
                'created_at' => now()->toDateTimeString(),
            ]);
            $service->storeMany(new Collection([$model]));
        }
    }

    $mixedTime = microtime(true) - $startTime;

    echo "[Mixed] 50/50 read/write × {$mixedOps} ops\n";
    echo '  Total time: '.number_format($mixedTime * 1000, 2)." ms\n";
    echo '  Throughput: '.number_format($mixedOps / $mixedTime, 0)." ops/sec\n";
    echo '  Per operation: '.number_format(($mixedTime / $mixedOps) * 1000, 4)." ms\n\n";

    // --- Partial Hydration with pluck() ---
    if ($count >= 100) {
        $pluckCount = min($count, 5000);

        $service->clear();
        $service->storeMany($models);

        $startTime = microtime(true);

        for ($i = 0; $i < $pluckCount; $i++) {
            $service->pluck(['id', 'name', 'email'], ['role_id' => ($i % 5) + 1]);
        }

        $pluckTime = microtime(true) - $startTime;
        $pluckThroughput = $pluckCount / $pluckTime;

        echo "[Read] pluck(['id','name','email'], where) × {$pluckCount} queries\n";
        echo '  Total time: '.number_format($pluckTime * 1000, 2)." ms\n";
        echo '  Throughput: '.number_format($pluckThroughput, 0)." queries/sec\n";
        echo '  Per query: '.number_format(($pluckTime / $pluckCount) * 1000, 4)." ms\n\n";
    }

    // Cleanup
    $service->clear();
    echo "---\n\n";
}

echo "========================================\n";
echo "    Throughput Benchmark Complete\n";
echo "========================================\n";
