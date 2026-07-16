# Configuration

Publish: `php artisan vendor:publish --tag=redis-model-cache-config`

The package provides a typed `Configuration` DTO (`Sm_mE\RedisModelCache\Support\Configuration`) with 28 typed readonly properties. All internal config reads go through this DTO instead of raw `config()` calls, ensuring type safety at every access point. Use `Configuration::fromConfig()` to create an instance from the current Laravel config, or inject `Configuration` in your own services (it is auto-resolvable from the container).

| Key | Default | Description |
|-----|---------|-------------|
    | `config_version` | `'2.12.0'` | Configuration file schema version (triggers warning on mismatch) |
| `connection` | `'cache'` | Redis connection from `config/database.php` |
| `default_ttl` | `86400` | Default cache TTL in seconds (null = no expiry) |
| `hydrate_batch_size` | `5000` | Models per pipeline batch for hydrate/pluck |
| `scan_strategy` | `'scan'` | Deletion key discovery strategy |
| `stampede_protection.enabled` | `false` | Enable stampede prevention |
| `stampede_protection.lock_timeout` | `10` | Lock expiry (seconds) |
| `stampede_protection.wait_timeout` | `5` | Max wait for lock release (seconds) |
| `stampede_protection.wait_interval` | `100` | Poll interval (ms) |
| `stale_while_revalidate.enabled` | `false` | Enable SWR pattern |
| `stale_while_revalidate.grace_period` | `300` | SWR grace period (seconds) |
| `stale_while_revalidate.queue` | `'default'` | Queue for background jobs |
| `lua_scripting.enabled` | `true` | Use Lua for atomic stores |
| `compression.enabled` | `false` | Enable payload compression |
| `compression.algorithm` | `'gzip'` | `gzip`, `zstd`, or `lz4` |
| `compression.level` | `6` | Compression level (1-9) |
| `multi_tenant.enabled` | `false` | Enable tenant namespacing |
| `multi_tenant.resolver` | `null` | Tenant resolver class |
| `observability.enabled` | `true` | Enable metrics collection |
| `observability.dispatch_events` | `true` | Dispatch cache events |
| `observability.telescope` | `true` | Telescope integration |
| `observability.pulse` | `true` | Pulse integration |
| `observability.debug` | `false` | Debug logging |

> [!NOTE]
> During boot, the configuration is validated. If the configured `connection` is explicitly set to a non-null value but is not defined in `config/database.php`, a warning log will be emitted using `Log::warning()` instead of throwing an `InvalidArgumentException`, allowing the application boot to continue.

## Model-level configuration

Via `redisModelCacheConfig()` on the Eloquent trait:

```php
protected static function redisModelCacheConfig(): array
{
    return [
        'indexes' => ['role_id', 'status'],
        'sorted' => ['created_at'],
        'custom_indexes' => ['active_admins' => ['role_id' => 1, 'status' => 'active']],
        'ttl' => 3600,
        'connection' => null,
    ];
}
```

## Configuration Versioning

The configuration file contains a `config_version` key (e.g. `'2.9'`). When the package boots, it checks this version key. If the configuration version does not match the expected version, a warning log is emitted.

To update an outdated published configuration file, run:

```bash
php artisan vendor:publish --tag=redis-model-cache-config --force
```

