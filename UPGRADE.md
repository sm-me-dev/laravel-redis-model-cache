# Upgrade Guide

This guide covers supported major-version migrations. The Packagist package
name remains `sm-me/laravel-redis-model-cache` in every version.

## v1.x to v2.x

Version 2 introduced indexed Redis model caching and expanded configuration.

- Replace v1 service usage with `RedisModelService` and configure indexes
  explicitly.
- Publish the current config and review stampede protection, SWR, compression,
  Lua scripting, observability, and invalidation settings.
- Fields used by query methods must be declared as indexes.
- Clear and rebuild cached data after deployment; v2 changed the key layout
  and serialized payload format.

## v2.x to v3.0.0

Version 3 changed the PHP namespace while keeping the package name unchanged:

```text
SmmE\RedisModelCache  ->  SMDev\RedisModelCache
```

Update imports, traits, configured provider names, and fully-qualified class
strings. The Composer requirement remains:

```json
"sm-me/laravel-redis-model-cache": "^3.0"
```

For larger applications, publish the Rector migration config:

```bash
php artisan vendor:publish \
  --tag=redis-model-cache-rector \
  --force

vendor/bin/rector process \
  --config=rector.redis-model-cache.php
```

The published config scans only the consuming application's `app`, `tests`,
`database`, and `config` directories and never scans package files under
`vendor/`.

## API and configuration changes

### `selective()` to `pluck()`

`selective()` is deprecated. Replace:

```php
$cache->selective($fields, $where, $only);
```

with:

```php
$cache->pluck($fields, $where, $only);
```

The argument behavior is unchanged; `selective()` remains as a compatibility
wrapper during the migration window.

### Stampede protection and SWR

Stampede protection is enabled by default in v3. Set
`REDIS_MODEL_CACHE_STAMPEDE=false` only when duplicate rebuilds are acceptable.

SWR remains opt-in:

```env
REDIS_MODEL_CACHE_SWR=true
REDIS_MODEL_CACHE_SWR_GRACE=300
REDIS_MODEL_CACHE_SWR_QUEUE=default
```

SWR dispatches a background queue job when cached data is stale but within its
grace period. A queue worker must be running, and the `rememberAll()` callback
must be serializable. `laravel/serializable-closure` is a direct runtime
dependency for this supported feature.

### Published configuration

Refresh published configuration after upgrading:

```bash
php artisan vendor:publish \
  --tag=redis-model-cache-config \
  --force
```

Review local customizations before replacing the file. The provider warns
when the published `config_version` does not match the package.

## Cache invalidation and warming

Treat a major upgrade as a cache-layout change:

1. Drain workers that could write using the old code.
2. Clear the package cache namespace.
3. Deploy the new code and refreshed configuration.
4. Warm high-value models with the warmup command or a controlled job.
5. Restart queue workers so SWR and async invalidation use the new code.

For routine releases, model lifecycle invalidation is the correctness
mechanism; TTL is primarily for memory reclamation. Warm caches after a cold
deploy when predictable first-request latency matters.

See [CHANGELOG.md](CHANGELOG.md) for release-specific details and
[STABILITY.md](STABILITY.md) for the public compatibility commitment.
