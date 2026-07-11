#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Concurrency & Content Benchmark
 *
 * Simulates high-contention workloads: rapid reads interleaved with writes,
 * stampede protection lock contention, and SWR revalidation pressure.
 *
 * Run: php benchmarks/concurrency_benchmark.php
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sm_mE\RedisModelCache\RedisModelService;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/bootstrap.php';

class ConcurrencyModel extends Model
{
    protected $table = 'concurrency_bench';

    protected $fillable = ['id', 'name', 'email', 'role_id', 'status', 'score'];

    public $timestamps = false;
}

function createModels(int $count): Collection
{
    $models = [];
    for ($i = 1; $i <= $count; $i++) {
        $models[] = new ConcurrencyModel([
            'id' => $i,
            'name' => "User {$i}",
            'email' => "user{$i}@example.com",
            'role_id' => ($i % 5) + 1,
            'status' => $i % 2 === 0 ? 'active' : 'inactive',
            'score' => (float) ($i * 1.5),
        ]);
    }

    return new Collection($models);
}

$models = createModels(10000);

echo "============================================\n";
echo "    Concurrency & Contention Benchmark\n";
echo "============================================\n\n";

// ── 1. Read-heavy workload (90% read, 10% write) ───────────────────
echo "── 1. Read-heavy (90/10) ──\n";

$service = app(RedisModelService::class, [
    'model_class' => ConcurrencyModel::class,
    'indexes' => ['role_id', 'status'],
    'sorted' => ['score'],
    'ttl' => 3600,
]);
config()->set('redis-model-cache.lua_scripting.enabled', true);
$service->clear();
$service->storeMany($models);

$ops = 5000;
$startTime = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    if ($i % 10 === 0) {
        $m = new ConcurrencyModel([
            'id' => 20000 + $i,
            'name' => "Concurrent {$i}",
            'email' => "c{$i}@example.com",
            'role_id' => 1,
            'status' => 'active',
            'score' => 100.0,
        ]);
        $service->storeMany(new Collection([$m]));
    } else {
        $service->where(['role_id' => ($i % 5) + 1]);
    }
}
$elapsed = microtime(true) - $startTime;
echo "  {$ops} ops: ".number_format($elapsed * 1000, 2).' ms total, '
    .number_format($ops / $elapsed, 0).' ops/sec, '
    .number_format(($elapsed / $ops) * 1000, 4)." ms/op\n\n";

$service->clear();

// ── 2. Write-heavy workload (10% read, 90% write) ──────────────────
echo "── 2. Write-heavy (10/90) ──\n";

$service->storeMany($models);

$ops = 5000;
$startTime = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    if ($i % 10 !== 0) {
        $m = new ConcurrencyModel([
            'id' => 30000 + $i,
            'name' => "Writer {$i}",
            'email' => "w{$i}@example.com",
            'role_id' => 1,
            'status' => 'active',
            'score' => 100.0,
        ]);
        $service->storeMany(new Collection([$m]));
    } else {
        $service->where(['role_id' => 1]);
    }
}
$elapsed = microtime(true) - $startTime;
echo "  {$ops} ops: ".number_format($elapsed * 1000, 2).' ms total, '
    .number_format($ops / $elapsed, 0).' ops/sec, '
    .number_format(($elapsed / $ops) * 1000, 4)." ms/op\n\n";

$service->clear();

// ── 3. Balanced workload (50/50) ───────────────────────────────────
echo "── 3. Balanced (50/50) ──\n";

$service->storeMany($models);

$ops = 5000;
$startTime = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    if ($i % 2 === 0) {
        $service->where(['role_id' => ($i % 5) + 1]);
    } else {
        $m = new ConcurrencyModel([
            'id' => 40000 + $i,
            'name' => "Balanced {$i}",
            'email' => "b{$i}@example.com",
            'role_id' => ($i % 5) + 1,
            'status' => 'active',
            'score' => 100.0,
        ]);
        $service->storeMany(new Collection([$m]));
    }
}
$elapsed = microtime(true) - $startTime;
echo "  {$ops} ops: ".number_format($elapsed * 1000, 2).' ms total, '
    .number_format($ops / $elapsed, 0).' ops/sec, '
    .number_format(($elapsed / $ops) * 1000, 4)." ms/op\n\n";

$service->clear();

// ── 4. Stampede protection overhead ─────────────────────────────────
echo "── 4. Stampede Protection Overhead ──\n";

$service->storeMany($models);

config()->set('redis-model-cache.stampede_protection.enabled', true);

$ops = 1000;
$startTime = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    $service->where(['role_id' => ($i % 5) + 1]);
}
$elapsed = microtime(true) - $startTime;
echo "  {$ops} where() queries (stampede ON): "
    .number_format($elapsed * 1000, 2).' ms total, '
    .number_format($ops / $elapsed, 0)." ops/sec\n\n";

// ── 5. SWR revalidation overhead ───────────────────────────────────
echo "── 5. SWR Revalidation Overhead ──\n";

config()->set('redis-model-cache.stampede_protection.enabled', false);
config()->set('redis-model-cache.stale_while_revalidate.enabled', true);

$service->clear();
$service->storeMany($models);

$redis = $service->getRedis();
$redis->hset('{concurrency_bench}:meta', 'cached_at', (string) (time() - 120));

$ops = 500;
$startTime = microtime(true);
for ($i = 0; $i < $ops; $i++) {
    $service->rememberAll(
        callback: fn () => collect([]),
        where: ['role_id' => 1],
        swr: true,
    );
}
$elapsed = microtime(true) - $startTime;
echo "  {$ops} SWR stale-reads: ".number_format($elapsed * 1000, 2).' ms total, '
    .number_format($ops / $elapsed, 0)." ops/sec\n\n";

$service->clear();

echo "============================================\n";
echo "    Concurrency Benchmark Complete\n";
echo "============================================\n";
