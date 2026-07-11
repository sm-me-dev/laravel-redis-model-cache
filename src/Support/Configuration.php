<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class Configuration
{
    public function __construct(
        public readonly string $connection = 'cache',
        public readonly ?int $defaultTtl = 86400,
        public readonly string $scanStrategy = 'scan',
        public readonly int $scanCount = 1000,
        public readonly int $hydrateBatchSize = 5000,
        public readonly bool $observabilityEnabled = true,
        public readonly bool $observabilityDispatchEvents = true,
        public readonly bool $observabilityTelescope = true,
        public readonly bool $observabilityPulse = true,
        public readonly bool $observabilityDebug = false,
        public readonly bool $stampedeProtectionEnabled = false,
        public readonly int $stampedeProtectionLockTimeout = 10,
        public readonly int $stampedeProtectionWaitTimeout = 5,
        public readonly int $stampedeProtectionWaitInterval = 100,
        public readonly bool $swrEnabled = false,
        public readonly int $swrGracePeriod = 300,
        public readonly string $swrQueue = 'default',
        public readonly bool $compressionEnabled = false,
        public readonly string $compressionAlgorithm = 'gzip',
        public readonly int $compressionLevel = 6,
        public readonly int $compressionMinSize = 512,
        public readonly bool $multiTenantEnabled = false,
        public readonly ?string $multiTenantResolver = null,
        public readonly string $multiTenantStrategy = 'header',
        public readonly string $multiTenantKey = 'X-Tenant-ID',
        public readonly bool $luaScriptingEnabled = true,
        public readonly string $invalidationStrategy = 'sync',
        public readonly bool $invalidationVersioned = false,
        public readonly string $invalidationQueue = 'default',
        public readonly string $redisFailureStrategy = 'exception',
        public readonly bool $redisFailureLog = true,
        public readonly string $redisFailureLogChannel = 'stack',
        public readonly mixed $redisFailureFallback = null,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('redis-model-cache');

        $observability = (array) ($config['observability'] ?? []);
        $stampede = (array) ($config['stampede_protection'] ?? []);
        $swr = (array) ($config['stale_while_revalidate'] ?? []);
        $compression = (array) ($config['compression'] ?? []);
        $multiTenant = (array) ($config['multi_tenant'] ?? []);
        $lua = (array) ($config['lua_scripting'] ?? []);
        $invalidation = (array) ($config['invalidation'] ?? []);
        $redisFailure = (array) ($config['redis_failure'] ?? []);

        return new self(
            connection: (string) ($config['connection'] ?? 'cache'),
            defaultTtl: array_key_exists('default_ttl', $config) && $config['default_ttl'] === null ? null : (int) ($config['default_ttl'] ?? 86400),
            scanStrategy: (string) ($config['scan_strategy'] ?? 'scan'),
            scanCount: (int) ($config['scan_count'] ?? 1000),
            hydrateBatchSize: (int) ($config['hydrate_batch_size'] ?? 5000),
            observabilityEnabled: (bool) ($observability['enabled'] ?? true),
            observabilityDispatchEvents: (bool) ($observability['dispatch_events'] ?? true),
            observabilityTelescope: (bool) ($observability['telescope'] ?? true),
            observabilityPulse: (bool) ($observability['pulse'] ?? true),
            observabilityDebug: (bool) ($observability['debug'] ?? false),
            stampedeProtectionEnabled: (bool) ($stampede['enabled'] ?? false),
            stampedeProtectionLockTimeout: (int) ($stampede['lock_timeout'] ?? 10),
            stampedeProtectionWaitTimeout: (int) ($stampede['wait_timeout'] ?? 5),
            stampedeProtectionWaitInterval: (int) ($stampede['wait_interval'] ?? 100),
            swrEnabled: (bool) ($swr['enabled'] ?? false),
            swrGracePeriod: (int) ($swr['grace_period'] ?? 300),
            swrQueue: (string) ($swr['queue'] ?? 'default'),
            compressionEnabled: (bool) ($compression['enabled'] ?? false),
            compressionAlgorithm: (string) ($compression['algorithm'] ?? 'gzip'),
            compressionLevel: (int) ($compression['level'] ?? 6),
            compressionMinSize: (int) ($compression['min_size'] ?? 512),
            multiTenantEnabled: (bool) ($multiTenant['enabled'] ?? false),
            multiTenantResolver: isset($multiTenant['resolver']) && $multiTenant['resolver'] !== '' ? (string) $multiTenant['resolver'] : null,
            multiTenantStrategy: (string) ($multiTenant['strategy'] ?? 'header'),
            multiTenantKey: (string) ($multiTenant['key'] ?? 'X-Tenant-ID'),
            luaScriptingEnabled: (bool) ($lua['enabled'] ?? true),
            invalidationStrategy: (string) ($invalidation['strategy'] ?? 'sync'),
            invalidationVersioned: (bool) ($invalidation['versioned'] ?? false),
            invalidationQueue: (string) ($invalidation['queue'] ?? 'default'),
            redisFailureStrategy: (string) ($redisFailure['strategy'] ?? 'exception'),
            redisFailureLog: (bool) ($redisFailure['log'] ?? true),
            redisFailureLogChannel: (string) ($redisFailure['log_channel'] ?? 'stack'),
            redisFailureFallback: $redisFailure['fallback_callback'] ?? null,
        );
    }
}
