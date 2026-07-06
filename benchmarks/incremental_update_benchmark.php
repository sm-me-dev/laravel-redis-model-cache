#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Incremental Update vs Full Store Benchmark
 *
 * Compares performance of:
 * - updateAttribute() / updateAttributes() (incremental updates)
 * - storeMany() (full model serialization)
 *
 * Run: php benchmarks/incremental_update_benchmark.php
 */

require __DIR__.'/bootstrap.php';

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use Sm_mE\RedisModelCache\RedisModelService;

// Create test model class
class BenchmarkModel extends Model
{
    protected $table = 'benchmark_models';

    protected $fillable = ['id', 'name', 'email', 'status', 'score'];

    public $timestamps = false;
}

// Configuration
$iterations = 1000;
$modelId = 1;

// Setup Redis service
$service = app(RedisModelService::class, [
    'model_class' => BenchmarkModel::class,
    'indexes' => ['status'],
    'sorted' => ['score'],
    'ttl' => 3600,
]);

// Clear cache before benchmarking
$service->clear();

// Create and store initial model
$model = new BenchmarkModel([
    'id' => $modelId,
    'name' => 'Test User',
    'email' => 'test@example.com',
    'status' => 'active',
    'score' => 100,
]);

$service->storeMany(collect([$model]));

echo "=== Incremental Update vs Full Store Benchmark ===\n";
echo "Iterations: {$iterations}\n";
echo "Model ID: {$modelId}\n";
echo 'Redis Connection: '.config('redis-model-cache.connection')."\n\n";

// ========================================
// Benchmark 1: updateAttribute()
// ========================================
echo "[1/3] Benchmarking updateAttribute() (single field)...\n";

$startTime = microtime(true);
$startMemory = memory_get_usage();

for ($i = 0; $i < $iterations; $i++) {
    $service->updateAttribute($modelId, 'name', "User {$i}");
}

$endTime = microtime(true);
$endMemory = memory_get_usage();

$updateAttributeTime = ($endTime - $startTime) * 1000; // Convert to ms
$updateAttributeMemory = ($endMemory - $startMemory) / 1024; // Convert to KB
$updateAttributePerOp = $updateAttributeTime / $iterations;

echo '  Total time: '.number_format($updateAttributeTime, 2)." ms\n";
echo '  Time per operation: '.number_format($updateAttributePerOp, 4)." ms\n";
echo '  Memory used: '.number_format($updateAttributeMemory, 2)." KB\n";
echo '  Operations/sec: '.number_format(1000 / $updateAttributePerOp, 0)."\n\n";

// ========================================
// Benchmark 2: updateAttributes()
// ========================================
echo "[2/3] Benchmarking updateAttributes() (multiple fields)...\n";

$startTime = microtime(true);
$startMemory = memory_get_usage();

for ($i = 0; $i < $iterations; $i++) {
    $service->updateAttributes($modelId, [
        'name' => "User {$i}",
        'email' => "user{$i}@example.com",
    ]);
}

$endTime = microtime(true);
$endMemory = memory_get_usage();

$updateAttributesTime = ($endTime - $startTime) * 1000;
$updateAttributesMemory = ($endMemory - $startMemory) / 1024;
$updateAttributesPerOp = $updateAttributesTime / $iterations;

echo '  Total time: '.number_format($updateAttributesTime, 2)." ms\n";
echo '  Time per operation: '.number_format($updateAttributesPerOp, 4)." ms\n";
echo '  Memory used: '.number_format($updateAttributesMemory, 2)." KB\n";
echo '  Operations/sec: '.number_format(1000 / $updateAttributesPerOp, 0)."\n\n";

// ========================================
// Benchmark 3: storeMany() (Full Store)
// ========================================
echo "[3/3] Benchmarking storeMany() (full model store)...\n";

$startTime = microtime(true);
$startMemory = memory_get_usage();

for ($i = 0; $i < $iterations; $i++) {
    $model->name = "User {$i}";
    $model->email = "user{$i}@example.com";
    $service->storeMany(collect([$model]));
}

$endTime = microtime(true);
$endMemory = memory_get_usage();

$storeManyTime = ($endTime - $startTime) * 1000;
$storeManyMemory = ($endMemory - $startMemory) / 1024;
$storeManyPerOp = $storeManyTime / $iterations;

echo '  Total time: '.number_format($storeManyTime, 2)." ms\n";
echo '  Time per operation: '.number_format($storeManyPerOp, 4)." ms\n";
echo '  Memory used: '.number_format($storeManyMemory, 2)." KB\n";
echo '  Operations/sec: '.number_format(1000 / $storeManyPerOp, 0)."\n\n";

// ========================================
// Comparison Summary
// ========================================
echo "=== Performance Comparison ===\n\n";

echo "updateAttribute() vs storeMany():\n";
$speedup1 = (($storeManyTime - $updateAttributeTime) / $storeManyTime) * 100;
echo '  Time improvement: '.number_format($speedup1, 1)."% faster\n";
echo '  Speedup factor: '.number_format($storeManyPerOp / $updateAttributePerOp, 2)."x\n\n";

echo "updateAttributes() vs storeMany():\n";
$speedup2 = (($storeManyTime - $updateAttributesTime) / $storeManyTime) * 100;
echo '  Time improvement: '.number_format($speedup2, 1)."% faster\n";
echo '  Speedup factor: '.number_format($storeManyPerOp / $updateAttributesPerOp, 2)."x\n\n";

echo "=== Recommendation ===\n";
echo 'For single-field updates: Use updateAttribute() - '.number_format($speedup1, 0)."% faster\n";
echo 'For multi-field updates: Use updateAttributes() - '.number_format($speedup2, 0)."% faster\n";
echo "For full model replacement: Use storeMany()\n\n";

// Cleanup
$service->clear();

echo "Benchmark complete!\n";
