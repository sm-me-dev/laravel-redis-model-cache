# Upgrade Guide — v2.12.0

Upgrading from **v2.10.x** or **v2.11.x** to **v2.12.0**.

## Quick Steps

### 1. Update Composer

```bash
composer require sm-me/laravel-redis-model-cache:^2.12
```

### 2. Re-publish Config (Required)

```bash
php artisan vendor:publish --tag=redis-model-cache-config --force
```

The config version check will warn you if your published config is stale.

### 3. Verify the Upgrade

```bash
php artisan redis-model-cache:inspect
```

## New Config Options

### `max_pipeline_size`

Controls internal pipeline chunking in `storeMany()`:

```php
'max_pipeline_size' => env('REDIS_MODEL_CACHE_MAX_PIPELINE', 5000),
```

When a batch exceeds this threshold, it is automatically split into multiple pipeline flushes to prevent memory spikes. The default (5,000) is the benchmark-verified sweet spot for throughput vs memory.

**When to reduce:** If your PHP memory limit is tight (< 128 MB), reduce to 2,000.

**When to increase:** If you benchmark higher throughput above 5,000 in your environment.

### `redis_failure` (new in v2.10.0)

```php
'redis_failure' => [
    'strategy' => env('REDIS_MODEL_CACHE_FAILURE_STRATEGY', 'exception'),
    'log' => env('REDIS_MODEL_CACHE_FAILURE_LOG', true),
    'log_channel' => env('REDIS_MODEL_CACHE_FAILURE_LOG_CHANNEL', 'stack'),
    'fallback_callback' => null,
],
```

Three strategies:
- `exception` (default) — re-throws `\RedisException`
- `log` — logs and returns null
- `fallback` — calls user-supplied callback

## Breaking Changes

**None.** All new features are backwards-compatible and opt-in. Existing applications can upgrade with zero code changes.

## Deprecations

- `selective()` — deprecated since v2.8.0, use `pluck()` instead

## If You Use Laravel 13

No changes needed. v2.12.0 fully supports Laravel 13 test matrix in CI (PHP 8.3 + 8.4).

## If You Use Octane

No changes needed. The Observability reset lifecycle is explicitly tested. Each worker tick starts with clean hit/miss counters.
