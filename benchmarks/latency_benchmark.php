#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Latency Percentile Benchmark
 *
 * Measures P50, P95, P99, P999 latency for read, write, and mixed operations.
 *
 * Run: php benchmarks/latency_benchmark.php
 *      php benchmarks/latency_benchmark.php --operations=5000
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sm_mE\RedisModelCache\RedisModelService;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../workbench/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Parse args
$operations = 2000;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--operations=')) {
        $operations = (int) substr($arg, 13);
    }
}

class LatencyModel extends Model
{
    protected $table = 'latency_bench';

    protected $fillable = ['id', 'name', 'email', 'role_id', 'status', 'score', 'created_at'];

    public $timestamps = false;
}

function percentile(array $samples, int $p): float
{
    if ($samples === []) {
        return 0.0;
    }
    sort($samples);
    $index = (int) ceil(($p / 100) * count($samples)) - 1;
    $index = max(0, min($index, count($samples) - 1));

    return $samples[$index];
}

function microtimeDiff(float $start): float
{
    return (microtime(true) - $start) * 1000; // milliseconds
}

$service = app(RedisModelService::class, [
    'model_class' => LatencyModel::class,
    'indexes' => ['role_id', 'status'],
    'sorted' => ['score'],
    'ttl' => 3600,
]);

// Pre-populate cache with 2000 records
$seedModels = [];
for ($i = 1; $i <= 2000; $i++) {
    $seedModels[] = new LatencyModel([
        'id' => $i,
        'name' => "User {$i}",
        'email' => "user{$i}@example.com",
        'role_id' => ($i % 5) + 1,
        'status' => $i % 2 === 0 ? 'active' : 'inactive',
        'score' => (float) ($i * 1.5),
        'created_at' => now()->subDays(2000 - $i)->toDateTimeString(),
    ]);
}
$service->storeMany(new Collection($seedModels));

echo "========================================\n";
echo "    Latency Percentile Benchmark\n";
echo "========================================\n";
echo "Operations per test: {$operations}\n\n";

// =============================
// 1. Read Latency (index lookup)
// =============================
$readLatencies = [];
for ($i = 0; $i < $operations; $i++) {
    $start = microtime(true);
    $service->where(['role_id' => ($i % 5) + 1]);
    $readLatencies[] = microtimeDiff($start);
}

echo "[Read] where(role_id) × {$operations}\n";
echo '  P50:   '.number_format(percentile($readLatencies, 50), 3)." ms\n";
echo '  P95:   '.number_format(percentile($readLatencies, 95), 3)." ms\n";
echo '  P99:   '.number_format(percentile($readLatencies, 99), 3)." ms\n";
echo '  P999:  '.number_format(percentile($readLatencies, 999), 3)." ms\n";
echo '  Avg:   '.number_format(array_sum($readLatencies) / count($readLatencies), 3)." ms\n";
echo '  Min:   '.number_format(min($readLatencies), 3)." ms\n";
echo '  Max:   '.number_format(max($readLatencies), 3)." ms\n\n";

// =============================
// 2. Write Latency (storeMany)
// =============================
$writeLatencies = [];
for ($i = 0; $i < min($operations, 1000); $i++) {
    $model = new LatencyModel([
        'id' => 10000 + $i,
        'name' => "Write User {$i}",
        'email' => "write{$i}@example.com",
        'role_id' => ($i % 5) + 1,
        'status' => $i % 2 === 0 ? 'active' : 'inactive',
        'score' => (float) ($i * 1.5),
        'created_at' => now()->toDateTimeString(),
    ]);
    $start = microtime(true);
    $service->storeMany(new Collection([$model]));
    $writeLatencies[] = microtimeDiff($start);
}

echo '[Write] storeMany(1 model) × '.count($writeLatencies)."\n";
echo '  P50:   '.number_format(percentile($writeLatencies, 50), 3)." ms\n";
echo '  P95:   '.number_format(percentile($writeLatencies, 95), 3)." ms\n";
echo '  P99:   '.number_format(percentile($writeLatencies, 99), 3)." ms\n";
echo '  P999:  '.number_format(percentile($writeLatencies, 999), 3)." ms\n";
echo '  Avg:   '.number_format(array_sum($writeLatencies) / count($writeLatencies), 3)." ms\n";
echo '  Min:   '.number_format(min($writeLatencies), 3)." ms\n";
echo '  Max:   '.number_format(max($writeLatencies), 3)." ms\n\n";

// =============================
// 3. Sorted Read Latency (whereBetween)
// =============================
$sortedLatencies = [];
for ($i = 0; $i < $operations; $i++) {
    $min = (float) ($i * 1.5);
    $max = $min + 500;
    $start = microtime(true);
    $service->whereBetween('score', $min, $max);
    $sortedLatencies[] = microtimeDiff($start);
}

echo "[Read] whereBetween(score) × {$operations}\n";
echo '  P50:   '.number_format(percentile($sortedLatencies, 50), 3)." ms\n";
echo '  P95:   '.number_format(percentile($sortedLatencies, 95), 3)." ms\n";
echo '  P99:   '.number_format(percentile($sortedLatencies, 99), 3)." ms\n";
echo '  P999:  '.number_format(percentile($sortedLatencies, 999), 3)." ms\n";
echo '  Avg:   '.number_format(array_sum($sortedLatencies) / count($sortedLatencies), 3)." ms\n";
echo '  Min:   '.number_format(min($sortedLatencies), 3)." ms\n";
echo '  Max:   '.number_format(max($sortedLatencies), 3)." ms\n\n";

// =============================
// 4. Bulk Write Latency (batch write)
// =============================
for ($batchSize = 10; $batchSize <= 1000; $batchSize *= 10) {
    $batches = max(1, intdiv(2000, $batchSize));
    $batchLatencies = [];

    $offset = 50000;
    for ($b = 0; $b < $batches; $b++) {
        $batch = [];
        for ($j = 0; $j < $batchSize; $j++) {
            $id = $offset + $b * $batchSize + $j;
            $batch[] = new LatencyModel([
                'id' => $id,
                'name' => "Batch User {$id}",
                'email' => "batch{$id}@example.com",
                'role_id' => ($id % 5) + 1,
                'status' => $id % 2 === 0 ? 'active' : 'inactive',
                'score' => (float) ($id * 1.5),
                'created_at' => now()->toDateTimeString(),
            ]);
        }
        $start = microtime(true);
        $service->storeMany(new Collection($batch));
        $batchLatencies[] = microtimeDiff($start);
    }

    echo "[Write] storeMany({$batchSize} models) × {$batches} batches\n";
    echo '  P50:   '.number_format(percentile($batchLatencies, 50), 3)." ms\n";
    echo '  P95:   '.number_format(percentile($batchLatencies, 95), 3)." ms\n";
    echo '  P99:   '.number_format(percentile($batchLatencies, 99), 3)." ms\n";
    echo '  Avg:   '.number_format(array_sum($batchLatencies) / count($batchLatencies), 3)." ms\n";
    echo '  Throughput: '.number_format($batchSize / (array_sum($batchLatencies) / count($batchLatencies) / 1000), 0)." models/sec\n\n";
}

// =============================
// 5. Partial Hydration Latency (pluck vs full hydrate)
// =============================
$pluckLatencies = [];
$fullLatencies = [];

for ($i = 0; $i < min($operations, 1000); $i++) {
    // pluck (partial)
    $start = microtime(true);
    $service->pluck(['id', 'name'], ['role_id' => ($i % 5) + 1]);
    $pluckLatencies[] = microtimeDiff($start);

    // full hydrate
    $start = microtime(true);
    $service->where(['role_id' => ($i % 5) + 1]);
    $fullLatencies[] = microtimeDiff($start);
}

echo '[Comparison] pluck vs full hydrate × '.count($pluckLatencies)."\n";
echo '  pluck P50:  '.number_format(percentile($pluckLatencies, 50), 3)." ms\n";
echo '  pluck P95:  '.number_format(percentile($pluckLatencies, 95), 3)." ms\n";
echo '  pluck P99:  '.number_format(percentile($pluckLatencies, 99), 3)." ms\n";
echo '  full  P50:  '.number_format(percentile($fullLatencies, 50), 3)." ms\n";
echo '  full  P95:  '.number_format(percentile($fullLatencies, 95), 3)." ms\n";
echo '  full  P99:  '.number_format(percentile($fullLatencies, 99), 3)." ms\n";

$pluckAvg = array_sum($pluckLatencies) / count($pluckLatencies);
$fullAvg = array_sum($fullLatencies) / count($fullLatencies);
if ($fullAvg > 0) {
    $improvement = (1 - $pluckAvg / $fullAvg) * 100;
    echo '  pluck is '.number_format($improvement, 1)."% faster\n\n";
}

// Cleanup
$service->clear();

echo "========================================\n";
echo "    Latency Benchmark Complete\n";
echo "========================================\n";
