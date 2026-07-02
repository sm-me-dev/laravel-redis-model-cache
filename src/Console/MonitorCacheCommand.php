<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Console;

use Illuminate\Console\Command;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;

class MonitorCacheCommand extends Command
{
    protected $signature = 'redis:monitor-cache
                            {action=info : Action to perform (info|keys|ttl|memory|clear)}
                            {--pattern=* : Pattern to match keys}
                            {--detailed : Show detailed information}';

    protected $description = 'Monitor and manage Redis model cache';

    public function handle(RedisConnectionResolver $connectionResolver): int
    {
        try {
            $redis = $connectionResolver->resolve();

            match ($this->argument('action')) {
                'info' => $this->showInfo($redis),
                'keys' => $this->showKeys($redis),
                'ttl' => $this->checkTTL($redis),
                'memory' => $this->showMemory($redis),
                'clear' => $this->clearCache($redis),
                default => $this->error('Invalid action. Use: info, keys, ttl, memory, or clear'),
            };

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Redis connection failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function showInfo($redis): void
    {
        $this->info('Redis Cache Information');
        $this->newLine();

        $info = $redis->info();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Redis Version', $info['redis_version'] ?? 'N/A'],
                ['Uptime (days)', round(($info['uptime_in_seconds'] ?? 0) / 86400, 2)],
                ['Connected Clients', $info['connected_clients'] ?? 'N/A'],
                ['Used Memory', $this->formatBytes($info['used_memory'] ?? 0)],
                ['Used Memory Peak', $this->formatBytes($info['used_memory_peak'] ?? 0)],
                ['Total Keys', $this->getTotalKeys($redis)],
                ['Expired Keys', $info['expired_keys'] ?? 'N/A'],
                ['Evicted Keys', $info['evicted_keys'] ?? 'N/A'],
            ]
        );
    }

    private function showKeys($redis): void
    {
        $patterns = $this->option('pattern') ?: ['*'];

        foreach ($patterns as $pattern) {
            $this->info("Keys matching pattern: {$pattern}");
            $this->newLine();

            $keys = $redis->keys($pattern);

            if (empty($keys)) {
                $this->warn('No keys found');

                continue;
            }

            $this->info('Found '.count($keys).' keys');

            if ($this->option('detailed')) {
                $data = [];
                foreach (array_slice($keys, 0, 50) as $key) {
                    $ttl = $redis->ttl($key);
                    $type = $redis->type($key);
                    $size = $this->getKeySize($redis, $key, $type);

                    $data[] = [
                        $key,
                        $type,
                        $ttl === -1 ? 'No TTL' : ($ttl === -2 ? 'Expired' : $ttl.'s'),
                        $size,
                    ];
                }

                $this->table(['Key', 'Type', 'TTL', 'Size'], $data);

                if (count($keys) > 50) {
                    $this->warn('Showing first 50 keys only');
                }
            } else {
                foreach (array_slice($keys, 0, 20) as $key) {
                    $this->line("  - {$key}");
                }

                if (count($keys) > 20) {
                    $this->warn('Showing first 20 keys. Use --detailed for more info');
                }
            }

            $this->newLine();
        }
    }

    private function checkTTL($redis): void
    {
        $patterns = $this->option('pattern') ?: ['*:hash', '*:index:*', '*:sorted:*', '*:custom:*'];

        $this->info('Checking TTL on cache keys');
        $this->newLine();

        $noTTL = [];
        $withTTL = [];

        foreach ($patterns as $pattern) {
            $keys = $redis->keys($pattern);

            foreach ($keys as $key) {
                $ttl = $redis->ttl($key);

                if ($ttl === -1) {
                    $noTTL[] = $key;
                } elseif ($ttl > 0) {
                    $withTTL[] = [$key, $ttl];
                }
            }
        }

        if (! empty($noTTL)) {
            $this->error('Keys WITHOUT TTL (Memory Leak Risk):');
            foreach (array_slice($noTTL, 0, 20) as $key) {
                $this->line("  ❌ {$key}");
            }
            if (count($noTTL) > 20) {
                $this->warn('... and '.(count($noTTL) - 20).' more');
            }
            $this->newLine();
        }

        if (! empty($withTTL)) {
            $this->info('Keys WITH TTL (Healthy):');
            foreach (array_slice($withTTL, 0, 10) as [$key, $ttl]) {
                $hours = round($ttl / 3600, 1);
                $this->line("  ✅ {$key} (expires in {$hours}h)");
            }
            if (count($withTTL) > 10) {
                $this->info('... and '.(count($withTTL) - 10).' more with TTL');
            }
        }

        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Keys with TTL', count($withTTL)],
                ['Keys without TTL', count($noTTL)],
            ]
        );
    }

    private function showMemory($redis): void
    {
        $this->info('Redis Memory Analysis');
        $this->newLine();

        $info = $redis->info('memory');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Used Memory', $this->formatBytes($info['used_memory'] ?? 0)],
                ['Used Memory RSS', $this->formatBytes($info['used_memory_rss'] ?? 0)],
                ['Used Memory Peak', $this->formatBytes($info['used_memory_peak'] ?? 0)],
                ['Memory Fragmentation Ratio', $info['mem_fragmentation_ratio'] ?? 'N/A'],
                ['Allocator', $info['mem_allocator'] ?? 'N/A'],
            ]
        );

        $this->newLine();
        $this->info('Memory by Key Pattern:');

        $patterns = ['*:hash', '*:index:*', '*:sorted:*', '*:custom:*'];
        $memoryData = [];

        foreach ($patterns as $pattern) {
            $keys = $redis->keys($pattern);
            $totalSize = 0;

            foreach ($keys as $key) {
                $type = $redis->type($key);
                $totalSize += $this->getKeySizeBytes($redis, $key, $type);
            }

            if ($totalSize > 0) {
                $memoryData[] = [
                    $pattern,
                    count($keys),
                    $this->formatBytes($totalSize),
                ];
            }
        }

        if (! empty($memoryData)) {
            $this->table(['Pattern', 'Keys', 'Estimated Size'], $memoryData);
        }
    }

    private function clearCache($redis): void
    {
        if (! $this->confirm('Are you sure you want to clear the Redis cache?')) {
            $this->info('Operation cancelled');

            return;
        }

        $patterns = $this->option('pattern');

        if (empty($patterns)) {
            $redis->flushdb();
            $this->info('✅ All cache cleared');
        } else {
            $totalDeleted = 0;
            foreach ($patterns as $pattern) {
                $keys = $redis->keys($pattern);
                if (! empty($keys)) {
                    $redis->del(...$keys);
                    $totalDeleted += count($keys);
                }
            }
            $this->info("✅ Deleted {$totalDeleted} keys");
        }
    }

    private function getTotalKeys($redis): int
    {
        $info = $redis->info('keyspace');

        foreach ($info as $key => $value) {
            if (str_starts_with($key, 'db')) {
                preg_match('/keys=(\d+)/', $value, $matches);

                return (int) ($matches[1] ?? 0);
            }
        }

        return 0;
    }

    private function getKeySize($redis, string $key, string $type): string
    {
        return $this->formatBytes($this->getKeySizeBytes($redis, $key, $type));
    }

    private function getKeySizeBytes($redis, string $key, string $type): int
    {
        return match ($type) {
            'string' => strlen($redis->get($key) ?? ''),
            'hash' => array_sum(array_map('strlen', $redis->hgetall($key))),
            'list' => array_sum(array_map('strlen', $redis->lrange($key, 0, -1))),
            'set' => array_sum(array_map('strlen', $redis->smembers($key))),
            'zset' => array_sum(array_map('strlen', $redis->zrange($key, 0, -1))),
            default => 0,
        };
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
