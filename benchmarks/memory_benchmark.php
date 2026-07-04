#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Memory Usage Benchmark
 *
 * Measures PHP and Redis memory usage at different cache scales.
 * Includes comparison of compressed vs uncompressed storage.
 *
 * Run: php benchmarks/memory_benchmark.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Sm_mE\RedisModelCache\RedisModelService;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../workbench/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

class MemoryModel extends Model
{
    protected $table = 'memory_bench';

    protected $fillable = ['id', 'name', 'email', 'role_id', 'status', 'score', 'bio', 'metadata'];

    public $timestamps = false;
}

function generateModel(int $id): MemoryModel
{
    // Simulate realistic model sizes: bio ≈ 200 chars, metadata ≈ 500 chars
    $bio = str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 4);
    $metadata = json_encode([
        'last_login' => now()->subDays(rand(0, 30))->toISOString(),
        'login_count' => rand(1, 1000),
        'preferences' => ['theme' => 'dark', 'locale' => 'en', 'timezone' => 'UTC'],
        'tags' => ['tag_'.($id % 20), 'tag_'.($id % 10)],
        'notes' => str_repeat('Sample text for realistic payload. ', 5),
    ], JSON_THROW_ON_ERROR);

    return new MemoryModel([
        'id' => $id,
        'name' => "User {$id} - ".str_repeat('name_suffix_', 3),
        'email' => "user{$id}.with.a.long.email.address@example.com",
        'role_id' => ($id % 10) + 1,
        'status' => $id % 3 === 0 ? 'active' : ($id % 3 === 1 ? 'inactive' : 'suspended'),
        'score' => (float) ($id * 1.5),
        'bio' => $bio,
        'metadata' => $metadata,
    ]);
}

function formatBytes(int $bytes): string
{
    if ($bytes === 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));

    return number_format($bytes / pow(1024, $i), 2).' '.$units[$i];
}

function measureRedisMemory(string $key): array
{
    try {
        $info = Redis::connection('cache')->info('memory');

        return [
            'used_memory' => (int) ($info['used_memory'] ?? 0),
            'used_memory_rss' => (int) ($info['used_memory_rss'] ?? 0),
            'used_memory_peak' => (int) ($info['used_memory_peak'] ?? 0),
        ];
    } catch (Exception $e) {
        return ['used_memory' => 0, 'used_memory_rss' => 0, 'used_memory_peak' => 0];
    }
}

$scales = [100, 1000, 5000];

echo "========================================\n";
echo "    Memory Usage Benchmark\n";
echo "========================================\n\n";

// --- Uncompressed ---
echo "━━━ Uncompressed Storage ━━━\n\n";

$service = app(RedisModelService::class, [
    'model_class' => MemoryModel::class,
    'indexes' => ['role_id', 'status'],
    'sorted' => ['score'],
    'ttl' => 3600,
]);

foreach ($scales as $count) {
    $models = [];
    for ($i = 1; $i <= $count; $i++) {
        $models[] = generateModel($i);
    }

    $service->clear();
    $phpMemBefore = memory_get_usage();
    $redisMemBefore = measureRedisMemory('{memory_bench}:hash');

    $startTime = microtime(true);
    $service->storeMany(new Collection($models));
    $storeTime = (microtime(true) - $startTime) * 1000;

    $phpMemAfter = memory_get_usage();
    $redisMemAfter = measureRedisMemory('{memory_bench}:hash');

    $hashLen = Redis::connection('cache')->hlen('{memory_bench}:hash');
    $payloadSize = 0;
    $samplePayload = Redis::connection('cache')->hget('{memory_bench}:hash', '1');
    if ($samplePayload !== null && $samplePayload !== false) {
        $payloadSize = strlen($samplePayload);
    }

    $redisMemDelta = ($redisMemAfter['used_memory'] - $redisMemBefore['used_memory']);

    echo "Scale: {$count} records\n";
    echo '  PHP memory delta: '.formatBytes($phpMemAfter - $phpMemBefore)."\n";
    echo '  Redis memory delta: '.formatBytes($redisMemDelta)."\n";
    if ($count > 0) {
        echo '  Per-record Redis: '.formatBytes((int) ($redisMemDelta / $count))."\n";
    }
    echo '  Average payload size: '.formatBytes($payloadSize)."\n";
    echo '  Hash length: '.$hashLen."\n";
    echo '  Store time: '.number_format($storeTime, 2)." ms\n\n";

    $service->clear();
}

// --- Compressed ---
echo "━━━ Compressed Storage (gzip, level 6) ━━━\n\n";

config(['redis-model-cache.compression.enabled' => true]);
config(['redis-model-cache.compression.algorithm' => 'gzip']);
config(['redis-model-cache.compression.level' => 6]);

$serviceCompressed = app(RedisModelService::class, [
    'model_class' => MemoryModel::class,
    'indexes' => ['role_id', 'status'],
    'sorted' => ['score'],
    'ttl' => 3600,
]);

foreach ($scales as $count) {
    $models = [];
    for ($i = 1; $i <= $count; $i++) {
        $models[] = generateModel($i);
    }

    $serviceCompressed->clear();
    $redisMemBefore = measureRedisMemory('{memory_bench}:hash');

    $startTime = microtime(true);
    $serviceCompressed->storeMany(new Collection($models));
    $storeTime = (microtime(true) - $startTime) * 1000;

    $redisMemAfter = measureRedisMemory('{memory_bench}:hash');

    $samplePayload = Redis::connection('cache')->hget('{memory_bench}:hash', '1');
    $compressedSize = $samplePayload !== null && $samplePayload !== false ? strlen($samplePayload) : 0;

    $originalPayload = generateModel(1);
    $originalJson = json_encode([
        'attributes' => $originalPayload->getAttributes(),
        'relations' => [],
    ], JSON_THROW_ON_ERROR);
    $originalSize = strlen($originalJson);

    $redisMemDelta = ($redisMemAfter['used_memory'] - $redisMemBefore['used_memory']);

    $compressionRatio = $originalSize > 0 ? (1 - $compressedSize / $originalSize) * 100 : 0;

    echo "Scale: {$count} records\n";
    echo '  Redis memory delta: '.formatBytes($redisMemDelta)."\n";
    if ($count > 0) {
        echo '  Per-record Redis: '.formatBytes((int) ($redisMemDelta / $count))."\n";
    }
    echo '  Original payload: '.formatBytes($originalSize).' → compressed: '.formatBytes($compressedSize)."\n";
    echo '  Compression ratio: '.number_format($compressionRatio, 1)."% reduction\n";
    echo '  Store time: '.number_format($storeTime, 2)." ms\n\n";

    $serviceCompressed->clear();
}

// --- Index Memory ---
echo "━━━ Index Memory Overhead ━━━\n\n";

$service->clear();
$models = [];
for ($i = 1; $i <= 5000; $i++) {
    $models[] = generateModel($i);
}

$redisMemBefore = measureRedisMemory('{memory_bench}:hash');
$service->storeMany(new Collection($models));
$redisMemAfter = measureRedisMemory('{memory_bench}:hash');

$totalRedisDelta = $redisMemAfter['used_memory'] - $redisMemBefore['used_memory'];

// Get all keys for this prefix
$keys = Redis::connection('cache')->keys('{memory_bench}:*');
$typeBreakdown = [];
foreach ($keys as $key) {
    $type = Redis::connection('cache')->type($key);
    $typeBreakdown[$type] = ($typeBreakdown[$type] ?? 0) + 1;
}

echo 'Total keys: '.count($keys)."\n";
echo 'Total Redis memory: '.formatBytes($totalRedisDelta)."\n";
echo 'Per-record total: '.formatBytes((int) ($totalRedisDelta / 5000))."\n";
echo "Key type breakdown:\n";
foreach ($typeBreakdown as $type => $count) {
    echo '  '.$type.': '.$count."\n";
}

// Cleanup
$service->clear();
config(['redis-model-cache.compression.enabled' => false]);

echo "\n========================================\n";
echo "    Memory Benchmark Complete\n";
echo "========================================\n";
