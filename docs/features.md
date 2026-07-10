# Features

Advanced features beyond basic cache operations.

## Stampede Protection

Prevents multiple processes from rebuilding the same cache simultaneously:

```php
$users = $cacheService->rememberAll(
    callback: fn() => User::where('active', true)->get(),
    where: ['active' => true],
    stampede: true
);
```

**How it works:**
1. First process acquires a Redis lock
2. Other processes wait (polling) for the lock to release
3. Lock holder rebuilds cache and releases lock
4. Waiters retry cache-read instead of rebuilding

**Config:**
```php
'stampede_protection' => [
    'enabled' => env('REDIS_MODEL_CACHE_STAMPEDE', false),
    'lock_timeout' => 10,
    'wait_timeout' => 5,
    'wait_interval' => 100, // ms between polls
],
```

When Lua scripting is enabled, uses compare-and-swap (CAS) for safe lock release.

## Stale-While-Revalidate (SWR)

Serve stale data immediately while refreshing in the background:

```php
$users = $cacheService->rememberAll(
    callback: fn() => User::where('active', true)->get(),
    where: ['active' => true],
    swr: true
);
```

**Cache states:**
| Age | Behavior |
|-----|----------|
| ≤ TTL | Fresh — serve from cache |
| TTL < age ≤ TTL + grace | Stale — serve stale data, dispatch background job |
| > TTL + grace | Expired — block and rebuild synchronously |

**Config:**
```php
'stale_while_revalidate' => [
    'enabled' => env('REDIS_MODEL_CACHE_SWR', false),
    'grace_period' => 300, // seconds
    'queue' => 'default',
],
```

> [!WARNING]
> The cache population callback closure passed to `rememberAll()` in SWR mode must be serializable using `Laravel\SerializableClosure`.
> 
> - Do **not** capture any non-serializable objects (such as database connection instances, PDO objects, anonymous classes, or open file/network resources).
> - All variables captured via `use (...)` must be serializable.
> - An `InvalidArgumentException` is thrown early during the background job's construction if serialization fails, helping debug serialization issues before they hit the queue.

### SWR Dispatch Deduplication
To prevent concurrent requests from flooding the queue with multiple background revalidation jobs when the cache becomes stale, the package implements an SWR dispatch lock.
- A lock is acquired at `"{prefix}:swr:lock"` using the configured `grace_period` as its TTL.
- Only the first request to trigger revalidation will successfully acquire the lock and dispatch the background queue job.
- Subsequent concurrent requests will continue to be served stale data immediately but will not trigger additional background jobs.
- Once the background revalidation job completes successfully, it releases the lock.

## Incremental Updates

Update specific attributes without full serialization (50-80% faster):

```php
$cacheService->updateAttribute(1, 'name', 'New Name');
$cacheService->updateAttributes(1, [
    'name' => 'New Name',
    'email' => 'newemail@example.com',
]);
```

- Only modifies specified attributes via `HSET`
- Automatically updates indexes when indexed fields change
- Preserves eager-loaded relations
- Uses pipelines for atomicity
- Validates that all updated keys exist on the model (checks cached payload keys, fillables, casts, and accessors/mutators) to prevent invalid attributes from slipping through

## Atomic Store via Lua

`store()` uses a single Lua script (`EVALSHA`) wrapping HSET + SREM + SADD + ZADD + EXPIRE:

```php
'config' => [
    'lua_scripting' => ['enabled' => true],
],
```

- Falls back to pipeline if Lua is disabled/unavailable
- SHA cached for fast-path on repeated calls
- Works with phpredis `OPT_PREFIX`

## Compression

Reduce Redis memory by 30-57%:

```php
'compression' => [
    'enabled' => env('REDIS_MODEL_CACHE_COMPRESS', false),
    'algorithm' => 'gzip',         // gzip | zstd | lz4
    'level' => 6,
],
```

| Algorithm | Memory Reduction | Speed |
|-----------|-----------------|-------|
| gzip | ~50% | Fast |
| zstd | ~57% | Medium |
| lz4 | ~30% | Fastest |

## Multi-Tenant Namespacing

Isolate cache per tenant with automatic key prefixing:

```php
'config' => [
    'multi_tenant' => [
        'enabled' => env('REDIS_MODEL_CACHE_MULTI_TENANT', false),
        'resolver' => \App\Services\TenantResolver::class,
    ],
],
```

Implement `TenantResolverInterface`:

```php
class TenantResolver implements TenantResolverInterface
{
    public function getTenantId(): string|int|null
    {
        return session('tenant_id');
    }
}
```

Keys become `tenant:{id}:{table}:hash` instead of `{table}:hash`.

## Explain Mode

Debug queries without executing them:

```php
$plan = $cacheService->explain()->where(['role_id' => 1]);
echo $plan->toString();
// QUERY PLAN:
// 1. SINTER "users:index:role_id:1"
// 2. Pipeline HGET × N "users:hash"
```

## Background Warmup

```bash
php artisan redis-model-cache:warmup "App\Models\User" \
    --where status=active \
    --indexes=status,role_id \
    --chunk=1000
```

Options: `--sorted`, `--ttl`, `--verbose` (memory stats). Progress bar with ETA included.
