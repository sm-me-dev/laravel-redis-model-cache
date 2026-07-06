<p align="center">
    <h1 align="center">Laravel Redis Model Cache</h1>
    <p align="center">High-performance Redis model caching for Laravel Eloquent — hash-based, index-aware, production-tested.</p>
</p>

<p align="center">
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/v/sm-me/laravel-redis-model-cache" alt="Latest Version"></a>
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/php-v/sm-me/laravel-redis-model-cache" alt="PHP Version"></a>
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/l/sm-me/laravel-redis-model-cache" alt="License"></a>
    <a href="https://codecov.io/gh/sm-me/laravel-redis-model-cache"><img src="https://codecov.io/gh/sm-me/laravel-redis-model-cache/branch/main/graph/badge.svg" alt="Code Coverage"></a>
</p>

---

**v2.0.0** | PHP ^8.4 | Laravel ^12.0

## Quick Start

```bash
composer require sm-me/laravel-redis-model-cache
php artisan vendor:publish --tag=redis-model-cache-config
```

```php
use Sm_mE\RedisModelCache\RedisModelService;

$cache = app(RedisModelService::class, [
    'model_class' => User::class,
    'indexes' => ['role_id', 'status'],
    'sorted' => ['created_at'],
    'ttl' => 3600,
]);

// Cache and retrieve
$users = $cache->rememberAll(
    callback: fn() => User::where('active', true)->get(),
    where: ['active' => true],
);

// Index lookup (no DB hit when warm)
$admins = $cache->where(['role_id' => 1]);
```

## Eloquent Trait

Enable auto-sync on save/delete:

```php
use Sm_mE\RedisModelCache\Concerns\HasRedisModelCache;

class User extends Model
{
    use HasRedisModelCache;

    protected static function redisModelCacheConfig(): array
    {
        return [
            'indexes' => ['role_id', 'status'],
            'sorted' => ['created_at'],
            'ttl' => 3600,
        ];
    }
}
```

Relations are automatically touched; stale indexes cleaned on attribute changes.

## Table of Contents

| Document | What's Inside |
|----------|---------------|
| [`docs/features.md`](docs/features.md) | Stampede protection, SWR, incremental updates, Lua atomicity, compression, multi-tenant, explain mode, warmup |
| [`docs/querying.md`](docs/querying.md) | `where`, `whereIn`, `whereBetween`, `orWhere`, `pluck`, sorted sets, pagination, custom indexes |
| [`docs/observability.md`](docs/observability.md) | Events, metrics collector, debug mode, inspect, Telescope/Pulse integration |
| [`docs/configuration.md`](docs/configuration.md) | Full config reference with defaults |
| [`docs/benchmarks.md`](docs/benchmarks.md) | Throughput, latency, memory benchmarks (up to 21k models/s, 57% memory reduction with compression) |

## Key Concepts

- **Index-driven queries** — all lookups go through Redis sets; full hash scans are blocked to prevent OOM
- **Stale index cleanup** — when an indexed attribute changes, old set entries are atomically removed
- **TTL propagation** — every key (hash, indexes, sorted sets) receives the same expire time
- **Cluster-safe** — hash tags (`{table}`) keep all keys for a model on the same cluster node

## License

MIT. See [LICENSE](LICENSE).
