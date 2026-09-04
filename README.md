# Laravel Redis Model Cache

Deterministic Redis caching for high-read Eloquent tables whose small set of
filter fields is known up front. It replaces repeated database reads with
indexed Redis lookups; it is **not** a general-purpose Eloquent query cache.

It does not support `!=`, `LIKE`, arbitrary `WHERE` clauses,
`orderBy`/`groupBy`/`join`, or `all()`. See
[the complete query limitations table](docs/query-limitations.md).

[![Latest Version](https://img.shields.io/packagist/v/sm-me/laravel-redis-model-cache)](https://packagist.org/packages/sm-me/laravel-redis-model-cache)
[![PHP Version](https://img.shields.io/packagist/php-v/sm-me/laravel-redis-model-cache)](https://packagist.org/packages/sm-me/laravel-redis-model-cache)
[![License](https://img.shields.io/packagist/l/sm-me/laravel-redis-model-cache)](https://packagist.org/packages/sm-me/laravel-redis-model-cache)

## What this does not do automatically

Use it when a model is read frequently, its common filters can be declared as
indexes, and predictable Redis-backed reads are worth the synchronous cache
maintenance on writes.

Do not use it for ad-hoc reporting queries, full-table scans, joins, or query
patterns that change constantly. The cache does not automatically turn
arbitrary Eloquent queries into Redis queries.

### Performance model

Model cache writes are synchronous by default. A `save()` or `delete()` on a
model using `HasRedisModelCache` updates Redis during the model event and adds
Redis latency to the request. See the
[write-path details](docs/architecture.md#performance-model).

## 60-second quick start

```bash
composer require sm-me/laravel-redis-model-cache
```

```php
use Illuminate\Database\Eloquent\Model;
use SMDev\RedisModelCache\Concerns\HasRedisModelCache;

class User extends Model
{
    use HasRedisModelCache;

    protected static function redisModelCacheConfig(): array
    {
        return ['indexes' => ['status']];
    }
}

$activeUsers = app(\SMDev\RedisModelCache\RedisModelService::class, [
    'model_class' => User::class,
    'indexes' => ['status'],
])->where(['status' => 'active']);
```

The trait keeps cached models and indexes synchronized on `saved`, `deleted`,
and `forceDeleted` events. The example uses one indexed `where()` lookup; add
only fields your application queries regularly.

## Full documentation

- [Architecture and performance model](docs/architecture.md)
- [Supported and unsupported queries](docs/query-limitations.md)
- [Configuration](docs/configuration.md)
- [Features, stampede protection, and SWR](docs/features.md)
- [Invalidation lifecycle](docs/invalidation.md)
- [Performance and capacity planning](docs/performance.md)
- [Observability and debugging](docs/observability.md)
- [Upgrade guide](UPGRADE.md)
- [Namespace stability commitment](STABILITY.md)
- [Static-analysis policy](docs/static-analysis.md)
- [Full historical usage reference](docs/usage-reference.md)

## Requirements

- PHP 8.3 or 8.4
- Laravel 11, 12, or 13
- Redis with phpredis or Predis

## License and support

MIT. See [LICENSE](LICENSE), [CONTRIBUTING.md](CONTRIBUTING.md), and
[SECURITY.md](SECURITY.md).
