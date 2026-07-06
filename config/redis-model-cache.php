<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    |
    | The Laravel Redis connection name to use for model caching.
    | Defaults to the 'cache' connection defined in config/database.php.
    */
    'connection' => env('REDIS_MODEL_CACHE_CONNECTION', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Default TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Default time-to-live for cache entries when no explicit TTL is provided
    | during service construction. Set to null for no expiration.
    */
    'default_ttl' => env('REDIS_MODEL_CACHE_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Scan Strategy
    |--------------------------------------------------------------------------
    |
    | Strategy used for key pattern matching during cache clear operations.
    | Supported: 'scan' (cursor-based, production-safe).
    | The legacy 'keys' command is NOT supported — it blocks Redis.
    */
    'scan_strategy' => env('REDIS_MODEL_CACHE_SCAN_STRATEGY', 'scan'),

    /*
    |--------------------------------------------------------------------------
    | Hydrate Batch Size
    |--------------------------------------------------------------------------
    |
    | Maximum number of models to hydrate in a single Redis pipeline call.
    | Larger batches improve throughput but consume more memory. For very
    | large result sets (>10K), keep this at 5000 or lower.
    */
    'hydrate_batch_size' => env('REDIS_MODEL_CACHE_HYDRATE_BATCH', 5000),

    /*
    |--------------------------------------------------------------------------
    | Scan Count
    |--------------------------------------------------------------------------
    |
    | Number of keys to inspect per SCAN iteration. Higher values reduce
    | round trips but may block Redis briefly. Default: 1000.
    */
    'scan_count' => env('REDIS_MODEL_CACHE_SCAN_COUNT', 1000),

    /*
    |--------------------------------------------------------------------------
    | Stampede Protection
    |--------------------------------------------------------------------------
    |
    | Prevents cache stampedes by using Redis locks (SET NX EX) to ensure
    | only one process rebuilds expired cache while others wait or use stale.
    */
    'stampede_protection' => [
        'enabled' => env('REDIS_MODEL_CACHE_STAMPEDE', false),
        'lock_timeout' => env('REDIS_MODEL_CACHE_LOCK_TIMEOUT', 10), // seconds
        'wait_timeout' => env('REDIS_MODEL_CACHE_WAIT_TIMEOUT', 5),  // seconds
        'wait_interval' => env('REDIS_MODEL_CACHE_WAIT_INTERVAL', 100), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    |
    | Enable event dispatching for cache operations to integrate with
    | Laravel Telescope, Pulse, or custom monitoring solutions.
    |
    | - telescope: Enable Telescope watcher integration for cache operations
    | - pulse: Enable Pulse card integration for live metrics
    | - debug: Enable verbose debug logging (DO NOT enable in production)
    */
    'observability' => [
        'enabled' => env('REDIS_MODEL_CACHE_OBSERVABILITY', true),
        'dispatch_events' => env('REDIS_MODEL_CACHE_EVENTS', true),
        'telescope' => env('REDIS_MODEL_CACHE_TELESCOPE', true),
        'pulse' => env('REDIS_MODEL_CACHE_PULSE', true),
        'debug' => env('REDIS_MODEL_CACHE_DEBUG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale-While-Revalidate (SWR)
    |--------------------------------------------------------------------------
    |
    | Serve stale cache data immediately while revalidating in background.
    | When cache expires but is within grace period, stale data is returned
    | and a background job is dispatched to refresh the cache asynchronously.
    |
    | - grace_period: Additional time (seconds) after TTL expires during which
    |                 stale data can be served while revalidating
    | - queue: Laravel queue name for revalidation jobs
    */
    'stale_while_revalidate' => [
        'enabled' => env('REDIS_MODEL_CACHE_SWR', false),
        'grace_period' => env('REDIS_MODEL_CACHE_SWR_GRACE', 300), // 5 minutes
        'queue' => env('REDIS_MODEL_CACHE_SWR_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    |
    | Compress cached payloads to reduce Redis memory usage by 30-50%.
    | Trade-off: +5-10ms write latency for memory savings.
    |
    | - algorithm: 'gzip' (widely supported), 'zstd' (best ratio, PHP 7.3+),
    |              'lz4' (fastest, requires ext-lz4)
    | - level: Compression level (1-9 for gzip, 1-22 for zstd)
    |          Higher = better compression but slower
    | - min_size: Minimum payload size (bytes) before compression is applied.
    |             Small payloads skip compression to avoid unnecessary CPU.
    |             Default: 512 (compression overhead > benefit below this)
    */
    'compression' => [
        'enabled' => env('REDIS_MODEL_CACHE_COMPRESS', false),
        'algorithm' => env('REDIS_MODEL_CACHE_COMPRESS_ALGO', 'gzip'),
        'level' => env('REDIS_MODEL_CACHE_COMPRESS_LEVEL', 6),
        'min_size' => env('REDIS_MODEL_CACHE_COMPRESS_MIN_SIZE', 512),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenant Namespacing
    |--------------------------------------------------------------------------
    |
    | Isolate cache data per tenant by prefixing keys with tenant ID.
    | Enables safe multi-tenancy without key collisions.
    |
    | Key format: {tenant:{tenant_id}:{table}}:{key_type}:{field}
    | Example:    {tenant:42:users}:hash
    |             {tenant:42:users}:index:status:active
    |
    | - resolver: Class implementing TenantResolverInterface with getTenantId().
    |             Example: App\Services\TenantResolver::class
    |             When null, falls back to RequestTenantResolver with the
    |             configured strategy and key.
    | - strategy: Resolution strategy for RequestTenantResolver:
    |             'header' (default), 'subdomain', 'auth', 'session'
    | - key:      Header name, session key, or user attribute for the tenant ID.
    |             Default: 'X-Tenant-ID' (for header strategy)
    */
    'multi_tenant' => [
        'enabled' => env('REDIS_MODEL_CACHE_MULTI_TENANT', false),
        'resolver' => env('REDIS_MODEL_CACHE_TENANT_RESOLVER', null),
        'strategy' => env('REDIS_MODEL_CACHE_TENANT_STRATEGY', 'header'),
        'key' => env('REDIS_MODEL_CACHE_TENANT_KEY', 'X-Tenant-ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lua Scripting
    |--------------------------------------------------------------------------
    |
    | Enable atomic Lua scripts for operations that benefit from atomicity:
    | stale index cleanup during model storage, and compare-and-swap lock
    | release for stampede protection.
    |
    | When disabled, the package falls back to pipelined commands (same
    | behavior as before v1.2.0).
    |
    | Disable if your Redis does not support scripting (Redis < 2.6) or
    | if you use a Redis-compatible service without Lua support.
    */
    'lua_scripting' => [
        'enabled' => env('REDIS_MODEL_CACHE_LUA', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invalidation
    |--------------------------------------------------------------------------
    |
    | Deterministic cache invalidation on model lifecycle events.
    | No auto-inference — every cleanup step is explicit.
    |
    | - strategy: 'sync' (immediate Redis ops) or 'async' (queue job)
    | - versioned: When true, increments a version counter in {table}:meta
    |              on each save/delete. External systems can poll for changes.
    | - queue: Queue name for async invalidation jobs
    */
    'invalidation' => [
        'strategy' => env('REDIS_MODEL_CACHE_INVALIDATION_STRATEGY', 'sync'),
        'versioned' => env('REDIS_MODEL_CACHE_VERSIONED', false),
        'queue' => env('REDIS_MODEL_CACHE_INVALIDATION_QUEUE', 'default'),
    ],
];
