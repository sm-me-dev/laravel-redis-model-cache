<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Console;

use Illuminate\Console\Command;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\Support\CacheManager;
use Sm_mE\RedisModelCache\Support\Configuration;

class DebugCommand extends Command
{
    protected $signature = 'redis-cache:debug
                             {--json : Output as JSON}';

    public function __construct()
    {
        parent::__construct();

        $this->setAliases(['redis-model-cache:debug']);
    }

    protected $description = 'Inspect Redis model cache state, metrics, and configuration';

    public function handle(
        RedisConnectionResolver $connectionResolver,
        CacheManager $cacheManager,
    ): int {
        try {
            $redis = $connectionResolver->resolve();
            $info = $redis->info();
            $metrics = $cacheManager->metrics();
            $config = Configuration::fromConfig();

            if ($this->option('json')) {
                $this->line(json_encode([
                    'redis' => $metrics->toArray()['redis'],
                    'metrics' => $metrics->toArray(),
                    'config' => $config,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->renderRedisInfo($info, $metrics);
            $this->renderMetrics($metrics);
            $this->renderConfig($config);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Redis connection failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function renderRedisInfo(array $info, mixed $metrics): void
    {
        $this->info('╔══════════════════════════════════╗');
        $this->info('║  Redis Model Cache — Debug       ║');
        $this->info('╚══════════════════════════════════╝');
        $this->newLine();

        $this->info('Redis Server');
        $this->line('  Version:          '.($info['redis_version'] ?? 'N/A'));
        $this->line('  Uptime:           '.round(($info['uptime_in_seconds'] ?? 0) / 86400, 2).' days');
        $this->line('  Connected clients: '.($info['connected_clients'] ?? 'N/A'));
        $this->line('  Total keys:       '.($metrics->toArray()['redis']['total_keys'] ?? 'N/A'));
        $this->line('  Expired keys:     '.($info['expired_keys'] ?? 'N/A'));
        $this->line('  Used memory:      '.$this->formatBytes((int) ($info['used_memory'] ?? 0)));
        $this->line('  Peak memory:      '.$this->formatBytes((int) ($info['used_memory_peak'] ?? 0)));
        $this->newLine();
    }

    private function renderMetrics(mixed $metrics): void
    {
        $this->info('Cache Metrics');
        $this->line('  Hit rate:         '.($metrics->toArray()['requests']['hit_rate'] ?? 'N/A').'%');
        $this->line('  Miss rate:        '.($metrics->toArray()['requests']['miss_rate'] ?? 'N/A').'%');
        $this->line('  Total requests:   '.($metrics->toArray()['requests']['total_requests'] ?? 0));
        $this->line('  Cache hits:       '.($metrics->toArray()['requests']['hits'] ?? 0));
        $this->line('  Cache misses:     '.($metrics->toArray()['requests']['misses'] ?? 0));
        $this->newLine();

        $latency = $metrics->toArray()['latency'];
        $this->info('Latency (ms)');
        $this->line('  P50:              '.($latency['p50'] ?? 'N/A'));
        $this->line('  P95:              '.($latency['p95'] ?? 'N/A'));
        $this->line('  P99:              '.($latency['p99'] ?? 'N/A'));
        $this->line('  Average:          '.($latency['average'] ?? 'N/A'));
        $this->line('  Min / Max:        '.($latency['min'] ?? 'N/A').' / '.($latency['max'] ?? 'N/A'));
        $this->line('  Samples:          '.($latency['samples'] ?? 0));
        $this->newLine();

        $cleanup = $metrics->toArray()['stale_cleanup'];
        $this->info('Stale Cleanup');
        $this->line('  Events:           '.$cleanup['count']);
        $this->line('  Keys removed:     '.$cleanup['keys_removed']);
        $this->line('  Lock contentions: '.$metrics->toArray()['lock_contention']);
        $this->newLine();
    }

    private function renderConfig(Configuration $config): void
    {
        $this->info('Configuration');
        $this->table(
            ['Key', 'Value'],
            [
                ['Connection', $config->connection],
                ['Default TTL', (string) $config->defaultTtl],
                ['Compression', $config->compressionEnabled ? $config->compressionAlgorithm : 'disabled'],
                ['Events', $config->observabilityDispatchEvents ? 'enabled' : 'disabled'],
                ['Debug mode', $config->observabilityDebug ? 'enabled' : 'disabled'],
                ['SWR', $config->swrEnabled ? 'enabled' : 'disabled'],
                ['Stampede protection', $config->stampedeProtectionEnabled ? 'enabled' : 'disabled'],
                ['Invalidation strategy', $config->invalidationStrategy],
                ['Versioned keys', $config->invalidationVersioned ? 'yes' : 'no'],
                ['Multi-tenant', $config->multiTenantEnabled ? 'yes' : 'no'],
                ['Lua scripting', $config->luaScriptingEnabled ? 'enabled' : 'disabled'],
            ]
        );

        $this->newLine();
        $this->info('Tip: Use --json for machine-parseable output.');
    }

    private function formatBytes(int $bytes): string
    {
        return \formatBytes($bytes);
    }
}
