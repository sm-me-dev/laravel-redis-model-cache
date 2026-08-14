# Agent Prompts: laravel-redis-model-cache Improvement Plan

**Repository:** `https://github.com/sm-me-dev/laravel-redis-model-cache`
**Current version:** v2.12.1
**PHP:** 8.3+ | **Laravel:** 11–13

Each phase is a self-contained prompt. Run them in order — later phases
depend on work from earlier ones. Each prompt is written to be pasted
directly into an AI coding agent (Claude Code, Cursor, Copilot Workspace,
etc.) with the repo already cloned and open.

---

## Phase 1 — Namespace + PSR compliance (breaking, tag as v3.0.0)

```
You are working on the Laravel package at the root of this repository.
The current vendor namespace is `SmmE\RedisModelCache`, which violates
PSR-1 (namespace segments must be PascalCase, no underscores).

Your task: rename the namespace to `SmmE\RedisModelCache` across the
entire codebase. This is a breaking change and will become v3.0.0.

Steps:

1. Search every PHP file under `src/`, `tests/`, `workbench/`, and
   `config/` for the string `SmmE\RedisModelCache` and replace it with
   `SmmE\RedisModelCache`.

2. Update `composer.json`:
   - Change `"name"` from `"sm-me/laravel-redis-model-cache"` to
     `"smme/laravel-redis-model-cache"` (Packagist slug must be lowercase).
   - Update all `autoload.psr-4` and `autoload-dev.psr-4` keys:
     `"SmmE\\RedisModelCache\\"` → `"SmmE\\RedisModelCache\\"`,
     `"SmmE\\RedisModelCache\\Tests\\"` → `"SmmE\\RedisModelCache\\Tests\\"`.

3. Update `src/Support/helpers.php`: the constant string
   `'SmmE\\RedisModelCache\\Concerns\\HasRedisModelCache'` inside
   `CacheManager::FACADE_TRAIT` must be updated to
   `'SmmE\\RedisModelCache\\Concerns\\HasRedisModelCache'`.

4. Search `README.md`, `CHANGELOG.md`, all `docs/` markdown files, and
   any RELEASE_NOTES files for `SmmE` and replace with `SmmE`.

5. Update the `_config.yml` (GitHub Pages config) if it references the
   old namespace.

6. Run `composer dump-autoload` to verify the new autoload map resolves.

7. Run `vendor/bin/pint` to reformat if needed.

8. Run `vendor/bin/pest` and confirm the existing test suite still passes
   (or fix any namespace reference failures — do not rewrite test logic,
   only fix import statements).

Do NOT change any logic, method signatures, or behaviour in this phase.
Only rename the namespace. When done, confirm the final grep count of
remaining `SmmE` occurrences is zero.
```

---

## Phase 2 — Fix interface contract violations + clean up deprecated API

```
You are working on the Laravel package at the root of this repository
(namespace: `SmmE\RedisModelCache` after Phase 1).

Your task: fix two interface contract violations and formally remove the
deprecated blind-DEL lock release. Do not introduce new features yet.

### 2a — Remove `all()` from the `ModelCacheService` interface

The `ModelCacheService` interface declares `all()` but the implementation
always throws `BadMethodCallException`. This violates the Liskov
Substitution Principle — consumers who code to the interface cannot rely
on it. Fix:

1. Remove the `all()` method declaration (and its docblock) from
   `src/Contracts/ModelCacheService.php`.

2. In `src/RedisModelService.php`, keep the `all()` method but change
   the annotation above it from `@deprecated 3.1.0` to `@internal` and
   update its docblock to say: "Intentionally throws — full hash scans
   are prohibited for memory safety. This method is not part of the
   public contract."

3. Update any test that calls `$service->all()` via the interface type
   to call it via the concrete `RedisModelService` type instead.

### 2b — Remove the deprecated `StampedeProtection::releaseLock()` method

1. Delete the `releaseLock()` method from
   `src/Support/StampedeProtection.php` entirely (it was deprecated in
   3.2.0 as an unsafe blind DEL).

2. Search the entire codebase for any remaining call sites of
   `StampedeProtection::releaseLock(` and replace each with
   `StampedeProtection::releaseLockCas(` using the CAS pattern. (There
   should be none in `src/` at this point, but confirm.)

3. Remove any test that specifically tests the old `releaseLock()` method.
   Add a test asserting the method no longer exists:
   ```php
   it('does not expose the unsafe blind-DEL lock release', function () {
       expect(method_exists(StampedeProtection::class, 'releaseLock'))
           ->toBeFalse();
   });
   ```

### 2c — Improve exception messages in `IndexResolver`

In `src/Support/IndexResolver.php`, the exception message for a missing
index currently says:
  `"Field '{field}' is not indexed. Declare it in $indexes.
   Available: [...]"`

This is already good, but if `$availableIndexes` is empty the message
reads "Available: []" which is confusing. Update both `resolve()` and
`resolveWhereIn()` to produce:
- When `$availableIndexes` is empty:
  `"Field '{field}' is not indexed. No indexes are declared for this
   service. Pass 'indexes' when constructing RedisModelService."`
- When `$availableIndexes` is non-empty (existing message, no change):
  `"Field '{field}' is not indexed. Available indexes: [status, role_id]."`

### 2d — Make global helper functions opt-in

The file `src/Support/helpers.php` is currently auto-loaded for every
app via the `"files"` key in `composer.json`. The function `formatBytes()`
is too generic a name to impose globally.

1. Remove `"src/Support/helpers.php"` from the `"files"` array in
   `composer.json`.

2. In the `RedisModelCacheServiceProvider::boot()` method, add:
   ```php
   if (config('redis-model-cache.load_helpers', true)) {
       require_once __DIR__.'/../Support/helpers.php';
   }
   ```

3. Add `'load_helpers' => env('REDIS_MODEL_CACHE_HELPERS', true),` to
   `config/redis-model-cache.php` with a comment explaining users can
   set it to `false` if `formatBytes` or the other helpers conflict.

Run `vendor/bin/pest` after each sub-task. All 352 existing tests must
still pass. Commit this phase as "fix: remove LSP violation, unsafe lock
method, and global helper pollution".
```

---

## Phase 3 — Split the god class

```
You are working on the Laravel package at the root of this repository
(namespace: `SmmE\RedisModelCache`).

`src/RedisModelService.php` is 2,448 lines and handles too many
concerns. Your task: extract three focused classes from it without
changing any public API or behaviour. All public methods must remain
accessible through `RedisModelService` exactly as before (it delegates
to the new classes). No method signatures change. No test should fail.

### Extract 1 — `src/Support/ModelHydrator.php`

Move these protected methods out of `RedisModelService` into a new
`final class ModelHydrator`:

- `hydrateIds(array $ids, bool $hydrate = true): Collection`
- `hydrateModelFromPayload(array $payload): Model`
- `restoreRelations(Model $model, array $relations): void`
- `hydrateRelatedModel(array $data): Model`

`ModelHydrator` constructor signature:
```php
public function __construct(
    private readonly string $modelClass,
    private readonly Configuration $configuration,
    // needs access to deserialize() — accept it as a callable
    private readonly \Closure $deserializer,
    private readonly mixed $redis,
    private readonly string $hashKey,
) {}
```

`RedisModelService` should construct a `ModelHydrator` in its own
constructor and store it as `$this->hydrator`. Replace all internal
calls to the moved methods with `$this->hydrator->methodName(...)`.

### Extract 2 — `src/Support/PipelineExecutor.php`

Move these methods into a new `final class PipelineExecutor`:

- `executePipeline(mixed $pipeline): array`
- `queueExpire($client, string $key): void`
- `queueLuaAtomicStoreOnClient(mixed $client, array $keys, array $args): void`
- `primeAtomicStoreScript(): void`

`PipelineExecutor` constructor:
```php
public function __construct(
    private readonly mixed $redis,
    private readonly Configuration $configuration,
    private readonly \Closure $luaExecutor, // wraps executeLua()
) {}
```

Store as `$this->pipeline` in `RedisModelService`.

### Extract 3 — `src/Support/ModelSerializer.php`

Move these methods into a new `final class ModelSerializer`:

- `serializeModel(Model $model): array`
- `extractRelations(Model $model): array`
- `extractScore(mixed $value): float`

`ModelSerializer` constructor:
```php
public function __construct(
    private readonly \Closure $serializer, // wraps serializeResult()
) {}
```

Store as `$this->serializer` in `RedisModelService`.

### Rules for this refactor

- Every extracted class lives in `src/Support/`.
- Every extracted class is `final` and has `declare(strict_types=1)`.
- `RedisModelService` keeps all public methods unchanged. It constructs
  the three helpers in `__construct()` and delegates to them.
- Do not change constructor parameters of `RedisModelService`.
- Do not touch `RedisBaseService`.
- After extraction, `RedisModelService` should be under 1,200 lines.
- Add a docblock to each new class explaining its single responsibility.
- Run `vendor/bin/phpstan analyse --verbose` and fix any type errors
  introduced by the extraction (do not add to the baseline).
- Run `vendor/bin/pest` — all tests must pass.

Commit as "refactor: extract ModelHydrator, PipelineExecutor, ModelSerializer".
```

---

## Phase 4 — Testing utilities (`RedisModelCacheFake`)

```
You are working on the Laravel package at the root of this repository
(namespace: `SmmE\RedisModelCache`).

Your task: add a test-double facade that lets consumers test their
Eloquent models without a running Redis instance. This is modelled on
Laravel's `Queue::fake()` / `Mail::fake()` pattern.

### 4a — Create `src/Testing/RedisModelCacheFake.php`

```php
<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use SmmE\RedisModelCache\Contracts\ModelCacheService;
use SmmE\RedisModelCache\Support\ExplainResult;

/**
 * In-memory stand-in for RedisModelService, suitable for unit testing.
 *
 * Usage in a test:
 *   RedisModelCacheFake::fake();
 *   // ... trigger model saves ...
 *   RedisModelCacheFake::assertStored(User::class, $userId);
 */
final class RedisModelCacheFake
{
    // ...
}
```

The fake must:

1. Keep an in-memory store: `array<class-string, array<string|int, Model>>`.

2. Track calls per operation: `array<string, int>` keyed by
   `"{modelClass}::{operation}"`.

3. Implement `ModelCacheService` so it can replace the real service
   in the container.

4. Expose a static `fake()` method that:
   - Binds itself into the app container as `ModelCacheService` and as
     `RedisModelService`.
   - Returns the fake instance for chaining.

5. Implement these `ModelCacheService` methods with in-memory logic:
   - `store(Model $model)` — saves to in-memory store, increments call counter
   - `find(int|string $id)` — looks up in-memory store
   - `where(array $where)` — filters in-memory store by attribute values
   - `delete(int|string $id)` — removes from in-memory store
   - `all()` — returns all stored models (fake allows it for convenience)
   - All other interface methods — throw `\BadMethodCallException` with
     message "Fake does not implement {method}. Stub it in your test."

6. Add these assertion methods (using `PHPUnit\Framework\Assert`):

```php
public function assertStored(string $modelClass, int|string $id): void;
public function assertNotStored(string $modelClass, int|string $id): void;
public function assertStoredCount(string $modelClass, int $count): void;
public function assertDeleted(string $modelClass, int|string $id): void;
public function assertNothingStored(): void;
// Returns all models stored for a class
public function storedFor(string $modelClass): Collection;
```

### 4b — Register the fake in the service provider

In `RedisModelCacheServiceProvider::register()`, do NOT bind the fake
by default. The static `RedisModelCacheFake::fake()` method handles that.

### 4c — Write tests for the fake itself

Create `tests/Unit/Testing/RedisModelCacheFakeTest.php`. Cover:

- `fake()` replaces the container binding
- `store()` + `assertStored()` pass
- `assertNotStored()` fails when model is stored (assert it throws)
- `find()` returns the correct model after `store()`
- `where()` filters correctly on a simple attribute
- `delete()` + `assertDeleted()` pass
- `assertNothingStored()` passes on a fresh fake and fails after a store
- `storedFor()` returns only models for the specified class

### 4d — Document in README

Add a "Testing" section to `README.md` after the "Quick Start" section:

```markdown
## Testing

Add `RedisModelCacheFake::fake()` in your test's `setUp()` to replace
Redis with an in-memory store:

```php
use SmmE\RedisModelCache\Testing\RedisModelCacheFake;

beforeEach(fn () => $fake = RedisModelCacheFake::fake());

it('stores a user when saved', function () use (&$fake) {
    $user = User::factory()->create();

    $fake->assertStored(User::class, $user->id);
});
```

No Redis connection is required. The fake supports `store`, `find`,
`where`, and `delete`. Other methods throw `BadMethodCallException` —
stub them in your test if needed.
```

Run `vendor/bin/pest` — all tests must pass.
Commit as "feat: add RedisModelCacheFake for testing without Redis".
```

---

## Phase 5 — PHP 8 attribute-based index config

```
You are working on the Laravel package at the root of this repository
(namespace: `SmmE\RedisModelCache`).

Your task: let developers declare cache indexes directly on Eloquent model
properties using PHP 8 attributes instead of the `redisModelCacheConfig()`
array. The array config must still work — attributes are an additional,
preferred option.

### 5a — Create the attribute classes

Create `src/Attributes/CacheIndex.php`:
```php
<?php
declare(strict_types=1);
namespace SmmE\RedisModelCache\Attributes;

use Attribute;

/** Marks this property as a cache index (Redis Set, equality lookup). */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class CacheIndex {}
```

Create `src/Attributes/CacheSorted.php`:
```php
#[Attribute(Attribute::TARGET_PROPERTY)]
final class CacheSorted {}
```

Create `src/Attributes/CacheWith.php`:
```php
#[Attribute(Attribute::TARGET_CLASS)]
final class CacheWith
{
    /** @param array<int, string> $relations */
    public function __construct(public readonly array $relations) {}
}
```

Create `src/Attributes/CacheTtl.php`:
```php
#[Attribute(Attribute::TARGET_CLASS)]
final class CacheTtl
{
    public function __construct(public readonly int $seconds) {}
}
```

### 5b — Create `src/Support/AttributeReader.php`

This class reads a model class's PHP attributes and returns a config
array in the same shape as `redisModelCacheConfig()`:

```php
final class AttributeReader
{
    /**
     * @param  class-string<Model>  $modelClass
     * @return array{indexes: list<string>, sorted: list<string>, with: list<string>, ttl: int|null}
     */
    public static function read(string $modelClass): array;
}
```

Implementation notes:
- Use `(new \ReflectionClass($modelClass))->getProperties()` to iterate
  properties.
- For each property, read attributes via `$property->getAttributes()`.
  A property with `CacheIndex` has its name added to `indexes[]`.
  A property with `CacheSorted` has its name added to `sorted[]`.
- For the class itself, read `CacheWith` → populate `with[]`.
  Read `CacheTtl` → populate `ttl`.
- Cache the result in a static `array<class-string, array>` property so
  reflection only runs once per model class per process.

### 5c — Merge attribute config into `HasRedisModelCache`

In `src/Concerns/HasRedisModelCache.php`, update
`resolveRedisModelCacheService()`:

```php
protected static function resolveRedisModelCacheService(): RedisModelService
{
    $modelClass = static::class;
    $arrayConfig = static::redisModelCacheConfig();       // existing array
    $attrConfig  = AttributeReader::read($modelClass);   // new attributes

    // Array config wins over attributes (explicit beats implicit)
    $indexes = $arrayConfig['indexes'] ?? $attrConfig['indexes'];
    $sorted  = $arrayConfig['sorted']  ?? $attrConfig['sorted'];
    $ttl     = $arrayConfig['ttl']     ?? $attrConfig['ttl'];
    $with    = $arrayConfig['with']    ?? $attrConfig['with'] ?? [];
    // ...existing params...
}
```

### 5d — Auto eager-load `$cacheWith` relations on store

In `HasRedisModelCache::processRedisModelCacheSaved()`, before calling
`$service->store($model)`, check the resolved `$with` relations:

```php
$with = $arrayConfig['with'] ?? $attrConfig['with'] ?? [];
if ($with !== [] && ! $model->relationLoaded(/* ... */)) {
    $model->loadMissing($with);
}
```

This means: if you declare `#[CacheWith(['roles', 'permissions'])]` on
the model class, the trait will automatically eager-load those relations
before storing — no manual code required.

### 5e — Write tests

Create `tests/Unit/Attributes/AttributeReaderTest.php`. Cover:
- A model with `#[CacheIndex]` on `status` and `role_id` returns
  `indexes: ['status', 'role_id']`.
- A model with `#[CacheSorted]` on `created_at` returns
  `sorted: ['created_at']`.
- A model with `#[CacheTtl(3600)]` returns `ttl: 3600`.
- A model with `#[CacheWith(['roles'])]` returns `with: ['roles']`.
- Array config overrides attribute config when both are present.
- `AttributeReader::read()` is idempotent (reflection runs once).

### 5f — Update README with attribute examples

Add an "Attribute-based config" section to `README.md`:

```markdown
### Attribute-based config (recommended)

```php
use SmmE\RedisModelCache\Attributes\CacheIndex;
use SmmE\RedisModelCache\Attributes\CacheSorted;
use SmmE\RedisModelCache\Attributes\CacheTtl;
use SmmE\RedisModelCache\Attributes\CacheWith;
use SmmE\RedisModelCache\Concerns\HasRedisModelCache;

#[CacheTtl(3600)]
#[CacheWith(['roles', 'permissions'])]
class User extends Model
{
    use HasRedisModelCache;

    #[CacheIndex]
    public string $status;

    #[CacheIndex]
    public int $role_id;

    #[CacheSorted]
    public int $created_at;
}
```

Array config (`redisModelCacheConfig()`) still works and takes priority
over attributes when both are present.
```

Run `vendor/bin/pest` — all tests must pass.
Commit as "feat: PHP 8 attribute-based index and TTL declaration".
```

---

## Phase 6 — Native pagination + sorted query `$limit/$offset`

```
You are working on the Laravel package at the root of this repository
(namespace: `SmmE\RedisModelCache`).

Your task: add native limit/offset support to `whereBetween()` and
a `LengthAwarePaginator`-compatible method for sorted queries. This
directly resolves the "No built-in pagination for sorted queries"
known limitation.

### 6a — Add `$limit` and `$offset` to `whereBetween()`

In `src/RedisModelService.php` and `src/Contracts/ModelCacheService.php`,
update the `whereBetween()` signature:

```php
public function whereBetween(
    string $field,
    int|float $min,
    int|float $max,
    bool $hydrate = true,
    ?array $only = null,
    ?int $limit = null,      // new
    int $offset = 0,         // new
): Collection|ExplainResult;
```

Inside `whereBetween()` in `RedisModelService`, change the Redis call
from:
```php
$ids = $this->redis->zrangebyscore($sortedKey, $min, $max);
```
to:
```php
$options = [];
if ($limit !== null) {
    $options['limit'] = [$offset, $limit];
}
$ids = $this->redis->zrangebyscore($sortedKey, $min, $max, $options);
```

The `$limit` and `$offset` parameters are optional and default to
`null`/`0` so existing callers are unaffected.

### 6b — Add `paginateWhereBetween()`

Add this method to `ModelCacheService` interface and `RedisModelService`:

```php
/**
 * Paginate models where a sorted field falls between min and max.
 *
 * Returns a LengthAwarePaginator so results integrate with Blade
 * pagination and JSON resources out of the box.
 *
 * @throws InvalidArgumentException If field is not a sorted index.
 */
public function paginateWhereBetween(
    string $field,
    int|float $min,
    int|float $max,
    int $perPage = 15,
    int $page = 1,
    bool $hydrate = true,
): \Illuminate\Pagination\LengthAwarePaginator;
```

Implementation:
1. Get total count: `ZCOUNT $sortedKey $min $max` → O(log N).
2. Get page IDs: call `whereBetween($field, $min, $max, false, null, $perPage, ($page - 1) * $perPage)` to get IDs only.
3. Hydrate IDs.
4. Return `new LengthAwarePaginator($items, $total, $perPage, $page)`.

### 6c — Also update `paginateSorted()` to accept an options array

The existing `paginateSorted()` method uses `ZREVRANGE` (rank-based).
No signature change is needed — it already takes `$page`/`$perPage`.
Just verify it delegates to the correct `ZREVRANGE` call with `LIMIT`
computed from `$page * $perPage` offset.

### 6d — Explain mode support

Update `QueryPlanner::plan()` for the `'whereBetween'` operation to
include `limit` and `offset` in the plan steps when they are set.

### 6e — Write tests

In `tests/Unit/RedisModelServiceTest.php`, add:

- `whereBetween with limit returns only N results` — mock `zrangebyscore`
  to verify `['limit' => [0, 5]]` is passed when `$limit = 5`.
- `whereBetween with offset starts at the right position` — verify
  `['limit' => [10, 5]]` for `$offset = 10, $limit = 5`.
- `paginateWhereBetween returns LengthAwarePaginator` — mock `zcount`
  returning 100, `zrangebyscore` returning 15 IDs, assert the paginator
  `total()` is 100 and `perPage()` is 15.

Run `vendor/bin/pest` — all tests must pass.
Commit as "feat: native limit/offset and paginateWhereBetween for sorted queries".
```

---

## Phase 7 — Distributed SWR lock + stampede defaults

```
You are working on the Laravel package at the root of this repository
(namespace: `SmmE\RedisModelCache`).

Your task: (a) make stampede protection on by default, and (b) replace
the per-process SWR lock with a Redis-backed distributed lock so it is
safe across multiple workers.

### 7a — Enable stampede protection by default

In `config/redis-model-cache.php`, change:
```php
'enabled' => env('REDIS_MODEL_CACHE_STAMPEDE', false),
```
to:
```php
'enabled' => env('REDIS_MODEL_CACHE_STAMPEDE', true),
```

Update the config comment to read:
```
// Enabled by default. Set REDIS_MODEL_CACHE_STAMPEDE=false to disable.
// Without stampede protection, concurrent requests on a cold cache will
// all hit the database simultaneously (thundering herd).
```

Update `src/Support/Configuration.php`:
```php
public readonly bool $stampedeProtectionEnabled = true,  // was false
```

Add a migration note to `CHANGELOG.md` under a new "v3.0.0" section:
"stampede_protection.enabled now defaults to true. Set
`REDIS_MODEL_CACHE_STAMPEDE=false` to restore the previous behaviour."

### 7b — Distributed SWR lock

The current SWR lock (`{table}:lock:swr`) is acquired with `SET NX EX`
but is checked only within the current PHP process. Under multiple
workers, two can race past the check before either acquires the lock.

Add a config key:
```php
// config/redis-model-cache.php, inside stale_while_revalidate:
'distributed_lock' => env('REDIS_MODEL_CACHE_SWR_LOCK', true),
'lock_ttl'         => env('REDIS_MODEL_CACHE_SWR_LOCK_TTL', 30),
```

In `src/Support/Configuration.php`, add:
```php
public readonly bool $swrDistributedLock = true,
public readonly int  $swrLockTtl = 30,
```

In `RedisModelService` (inside the SWR check path in `rememberAll()` /
`checkStaleStatus()`), update the revalidation dispatch logic:

```php
if ($this->configuration->swrEnabled && $this->configuration->swrDistributedLock) {
    $lockKey   = $this->keyBuilder->buildSWRLockKey();
    $lockValue = StampedeProtection::acquireLockWithValue(
        $this->redis,
        $lockKey,
        $this->configuration->swrLockTtl,
    );

    if ($lockValue === null) {
        // Another worker already holds the revalidation lock — skip dispatch
        return;
    }

    // Dispatch job; the job releases the lock on completion
    RevalidateCacheJob::dispatch(/* ... */)->onQueue(...)
        ->chain([new ReleaseSWRLockJob($lockKey, $lockValue)]);
}
```

Create `src/Jobs/ReleaseSWRLockJob.php`:
```php
final class ReleaseSWRLockJob implements ShouldQueue
{
    public function __construct(
        private readonly string $lockKey,
        private readonly string $lockValue,
    ) {}

    public function handle(): void
    {
        $redis = app(RedisConnectionResolver::class)->resolve();
        StampedeProtection::releaseLockCas($redis, $this->lockKey, $this->lockValue);
    }
}
```

### 7c — Remove the known limitation from docs

In `docs/known-limitations-v2.12.0.md` (or create a new
`docs/known-limitations-v3.0.0.md`), update item 2:

"No Distributed Lock for SWR Across Workers" → change status to
"Resolved in v3.0.0 — `stale_while_revalidate.distributed_lock` is
enabled by default. Set it to `false` to use the legacy per-process lock."

### 7d — Write tests

Add to `tests/Unit/StaleWhileRevalidateTest.php`:
- `distributed SWR lock prevents duplicate dispatch when lock is held` —
  mock `SET NX` to return null (lock already held), assert no job
  is dispatched.
- `distributed SWR lock allows dispatch when lock is free` — mock
  `SET NX` to return 'OK', assert job is dispatched.
- `ReleaseSWRLockJob calls releaseLockCas on handle` — assert the CAS
  method is called with the correct key and value.

Run `vendor/bin/pest` — all tests must pass.
Commit as "feat: distributed SWR lock and stampede-on-by-default".
```

---

## Phase 8 — First-party Laravel Pulse card

```
You are working on the Laravel package at the root of this repository
(namespace: `SmmE\RedisModelCache`).

Your task: ship a first-party Laravel Pulse card that visualises cache
health in the Pulse dashboard. The card must auto-register when Pulse
is installed — no user configuration required.

### 8a — Pulse recorder (already partially exists)

The file `src/Support/Pulse/ModelCacheRecorder.php` exists. Expand it to
record these metrics per model class:
- hit count (from `CacheHit` events)
- miss count (from `CacheMiss` events)
- average query execution time (from `QueryExecuted` events)
- total cached models (call `HLEN` on the model's hash key)

The recorder key should be `redis_model_cache` with entries shaped as:
```json
{
  "model": "App\\Models\\User",
  "hits": 1420,
  "misses": 38,
  "hit_rate": 97.4,
  "avg_query_ms": 0.8,
  "cached_count": 5200
}
```

Record data in the `pulse_values` table using Pulse's `$this->record()`
API (or the `Value` recorder pattern, depending on the installed Pulse
version — check for `Laravel\Pulse\Recorders\Concerns\Throttling`).

### 8b — Pulse card view

Create `resources/views/livewire/redis-model-cache-card.blade.php`.
The card should show:
- A title: "Redis Model Cache"
- A grid of metric rows, one per model class, with columns:
  Model | Cached | Hit rate | Avg query
- Colour code hit rate: green ≥ 95%, amber 80–95%, red < 80%.
- A "Last updated" timestamp.

Follow the Pulse card conventions (extend `<x-pulse::card>`).

### 8c — Pulse card Livewire component

Create `src/Support/Pulse/ModelCacheCard.php` (a Livewire component):
```php
namespace SmmE\RedisModelCache\Support\Pulse;

use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
class ModelCacheCard extends Card
{
    public function render(): \Illuminate\View\View
    {
        // Query pulse_values for 'redis_model_cache' entries
        // Return to the blade view
    }
}
```

### 8d — Auto-register in service provider

In `RedisModelCacheServiceProvider::boot()`:
```php
if (class_exists(\Laravel\Pulse\Facades\Pulse::class)) {
    \Livewire\Livewire::component(
        'redis-model-cache-card',
        \SmmE\RedisModelCache\Support\Pulse\ModelCacheCard::class,
    );
}
```

Publish the view:
```php
$this->publishes([
    __DIR__.'/../resources/views' => resource_path('views/vendor/redis-model-cache'),
], 'redis-model-cache-views');
```

### 8e — Update README

Add a "Laravel Pulse" section:
```markdown
## Laravel Pulse

When Laravel Pulse is installed, the Redis Model Cache card appears
automatically in your Pulse dashboard — no configuration required.

To add the card to your dashboard, publish the Pulse dashboard view
and add:
```html
<livewire:redis-model-cache-card cols="full" />
```

The card shows per-model hit rates, query latency, and total cached
records updated every 15 seconds.
```

### Rules

- If Pulse is not installed, none of this code should run (guard every
  Pulse reference with `class_exists`).
- Do not add `laravel/pulse` as a hard `require` — it stays in `suggest`.
- Write at least one test in `tests/Unit/Support/Pulse/` that verifies
  the recorder records a hit event correctly (mock the Pulse recorder
  dependency).

Run `vendor/bin/pest` — all tests must pass.
Commit as "feat: first-party Laravel Pulse card".
```

---

## Phase 9 — Cleanup, docs, and release prep

```
You are working on the Laravel package at the root of this repository
(namespace: `SmmE\RedisModelCache`). This is the final cleanup phase
before tagging v3.0.0.

### 9a — PHPStan baseline reduction

Run `vendor/bin/phpstan analyse --verbose` and review the current
baseline at `phpstan-baseline.neon`.

1. Fix all errors in `src/Console/DebugCommand.php` (the binary op on
   mixed errors are concrete and fixable — cast the mixed Redis info
   values to strings explicitly before concatenation).

2. For any remaining baseline entry where the fix is a simple cast or
   null-check, fix it and remove it from the baseline.

3. Leave in the baseline only errors that are genuine false positives
   from the Redis client returning `mixed` (phpredis returns mixed for
   almost everything). Add a comment above each remaining baseline
   group explaining why it cannot be fixed without a Redis stub.

4. Aim to get the baseline under 100 lines. Document the new count in
   the README under a "Static analysis" badge.

### 9b — Stale docs cleanup

1. Rewrite `docs/roadmap.md` to reflect the v3.0.0 feature set (all
   phases above). Remove all references to v1.x milestone language.

2. Update `CHAOS_REPORT.md` version header from `2.6.0` to `3.0.0`.

3. Create `docs/known-limitations-v3.0.0.md` by copying the v2.12.0
   file and updating:
   - Item 2 (SWR distributed lock) → mark as resolved.
   - Item 4 (no sorted pagination) → mark as resolved.
   - Add new item: "No Laravel Scout driver (planned for v3.1.0)."

4. Update `config/redis-model-cache.php` → change `'config_version'`
   to `'3.0.0'`.

5. Update `src/RedisModelCacheServiceProvider::validateConfigVersion()`
   to expect `'3.0.0'`.

### 9c — CHANGELOG

Write a proper `CHANGELOG.md` section for `v3.0.0` covering all changes
from Phases 1–8. Follow Keep a Changelog format:
- `### Breaking Changes` — namespace rename, stampede default change
- `### Added` — attributes, fake, Pulse card, distributed SWR lock,
  pagination
- `### Changed` — god class split, helpers opt-in
- `### Fixed` — LSP violation (all()), unsafe lock removal, exception
  messages
- `### Deprecated` — nothing new (selective() deprecation stays from
  v2.x)

### 9d — CI workflow

Create `.github/workflows/ci.yml` that:
- Triggers on push to `main` and on pull requests.
- Matrix: PHP `[8.3, 8.4]` × Laravel `[11.*, 12.*, 13.*]`.
- Steps: checkout → setup-php (with extensions: redis) → composer install
  → pest → pint (check only) → phpstan.
- Use `redis` service container (`redis:alpine`) so integration tests
  can run.

### 9e — Final verification

Run the full suite one last time:
```bash
composer dump-autoload
vendor/bin/pint --test    # must be clean
vendor/bin/phpstan analyse # must be ≤100 baseline lines
vendor/bin/pest            # all tests must pass
```

Confirm the following are true before tagging:
- [ ] Zero occurrences of `SmmE` anywhere in `src/` or `tests/`
- [ ] `RedisModelService.php` is under 1,200 lines
- [ ] PHPStan baseline is under 100 lines
- [ ] `RedisModelCacheFake` is importable and usable
- [ ] `#[CacheIndex]` attribute works on a model property
- [ ] `paginateWhereBetween()` exists on the interface
- [ ] CI workflow file exists at `.github/workflows/ci.yml`
- [ ] `config_version` is `3.0.0`

Commit as "chore: v3.0.0 release prep" and tag `v3.0.0`.
```

---

## Quick reference

| Phase | What it does | Risk | Est. effort |
|-------|-------------|------|-------------|
| 1 | Namespace rename → `SmmE\RedisModelCache` | Breaking (semver major) | 1–2 h |
| 2 | Fix LSP, remove unsafe lock, opt-in helpers | Low | 2–3 h |
| 3 | Split god class into 3 focused classes | Medium (refactor) | 4–6 h |
| 4 | `RedisModelCacheFake` testing utilities | Low | 3–4 h |
| 5 | PHP 8 attribute-based index config | Low | 3–4 h |
| 6 | `$limit/$offset` + `paginateWhereBetween()` | Low | 2–3 h |
| 7 | Distributed SWR lock + stampede default on | Medium | 3–4 h |
| 8 | First-party Laravel Pulse card | Low | 3–5 h |
| 9 | Cleanup, docs, CI, tag v3.0.0 | Low | 2–3 h |

**Total estimated effort:** 23–34 hours of focused agent-assisted work.
