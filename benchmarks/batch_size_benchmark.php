#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Batch Size Benchmark
 *
 * Compares different storeMany() batch sizes to find the optimal chunk
 * size for large datasets. Tests Lua vs Pipeline paths separately.
 *
 * Run: php benchmarks/batch_size_benchmark.php
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sm_mE\RedisModelCache\RedisModelService;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/bootstrap.php';

class BatchSizeModel extends Model
{
    protected $table = 'batch_size_bench';

    protected $fillable = ['id', 'name', 'email', 'role_id', 'status', 'score'];

    public $timestamps = false;
}

function createModels(int $count): Collection
{
    $models = [];
    for ($i = 1; $i <= $count; $i++) {
        $models[] = new BatchSizeModel([
            'id' => $i,
            'name' => "User {$i}",
            'email' => "user{$i}@example.com",
            'role_id' => ($i % 10) + 1,
            'status' => $i % 2 === 0 ? 'active' : 'inactive',
            'score' => (float) ($i * 1.5),
        ]);
    }

    return new Collection($models);
}

$batchSizes = [100, 500, 1000, 2000, 5000, 10000];
$totalRecords = 50000;

echo "================================================\n";
echo "    Batch Size Benchmark\n";
echo "================================================\n";
echo "Total records: {$totalRecords}\n";
echo 'Testing batch sizes: '.implode(', ', $batchSizes)."\n\n";

// ── With Lua scripting ─────────────────────────────────────────────
echo "── Lua Scripting: Enabled ──\n\n";

foreach ($batchSizes as $batchSize) {
    $models = createModels($totalRecords);
    $service = app(RedisModelService::class, [
        'model_class' => BatchSizeModel::class,
        'indexes' => ['role_id', 'status'],
        'sorted' => ['score'],
        'ttl' => 3600,
    ]);
    config()->set('redis-model-cache.lua_scripting.enabled', true);
    $service->clear();
    gc_collect_cycles();

    $startMem = memory_get_usage();
    $startTime = microtime(true);
    $errors = 0;

    foreach ($models->chunk($batchSize) as $batch) {
        try {
            $service->storeMany($batch);
        } catch (Throwable $e) {
            $errors++;
        }
    }

    $elapsed = microtime(true) - $startTime;
    $memUsed = memory_get_usage() - $startMem;

    echo "  Batch {$batchSize}: "
        .number_format($elapsed * 1000, 2).' ms total, '
        .number_format($totalRecords / $elapsed, 0).' models/sec, '
        .number_format($memUsed / 1024, 2).' KB memory'
        .($errors > 0 ? " ** {$errors} ERRORS **" : '')
        ."\n";

    $service->clear();
}

// ── Without Lua scripting ──────────────────────────────────────────
echo "\n── Lua Scripting: Disabled (Pipeline Fallback) ──\n\n";

config()->set('redis-model-cache.lua_scripting.enabled', false);

foreach ($batchSizes as $batchSize) {
    $models = createModels($totalRecords);
    $service = app(RedisModelService::class, [
        'model_class' => BatchSizeModel::class,
        'indexes' => ['role_id', 'status'],
        'sorted' => ['score'],
        'ttl' => 3600,
    ]);
    $service->clear();
    gc_collect_cycles();

    $startMem = memory_get_usage();
    $startTime = microtime(true);
    $errors = 0;

    foreach ($models->chunk($batchSize) as $batch) {
        try {
            $service->storeMany($batch);
        } catch (Throwable $e) {
            $errors++;
        }
    }

    $elapsed = microtime(true) - $startTime;
    $memUsed = memory_get_usage() - $startMem;

    echo "  Batch {$batchSize}: "
        .number_format($elapsed * 1000, 2).' ms total, '
        .number_format($totalRecords / $elapsed, 0).' models/sec, '
        .number_format($memUsed / 1024, 2).' KB memory'
        .($errors > 0 ? " ** {$errors} ERRORS **" : '')
        ."\n";

    $service->clear();
}

echo "\n================================================\n";
echo "    Batch Size Benchmark Complete\n";
echo "================================================\n";
