# Phase 1 — Validate Configuration

## Summary of changes
- Wrapped the Redis connection verification in a `try/catch` block within the `validateConfiguration()` method of the `RedisModelCacheServiceProvider`.
- Ensured that the connection check only throws an `InvalidArgumentException` if a connection name is explicitly configured (i.e. non-null) AND it is not defined under `database.redis`.
- Converted the caught `InvalidArgumentException` to a warning log utilizing `Log::warning()`, preventing application boot-up crashes for projects with missing or empty configurations.
- Added comprehensive unit tests in `ServiceProviderTest` verifying:
  - An invalid connection name logs a warning instead of throwing an exception.
  - A `null` connection name resolves silently without logging or throwing.
- Updated `docs/configuration.md` to reflect the updated validation behavior.

## Files modified
- [src/RedisModelCacheServiceProvider.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/src/RedisModelCacheServiceProvider.php)
- [tests/Unit/ServiceProviderTest.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/tests/Unit/ServiceProviderTest.php)
- [docs/configuration.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/configuration.md)
- [docs/reports/phase-1.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/reports/phase-1.md)

## Commands run and outcomes
- `vendor/bin/pint --test`
  - Status: Passed (automatically formatted formatting issues via `vendor/bin/pint`)
- `vendor/bin/phpstan analyse --no-progress`
  - Status: Passed (0 errors)
- `vendor/bin/phpunit`
  - Status: Passed (282 tests, 641 assertions)

## Any follow-ups or caveats
- Since connection validation now emits a warning log during provider boot, ensure systems monitor log entries if configuration mismatch debug-tracking is critical.

## Commit SHA
2428084a3426b00bedfa41aa2eb39191090f8df9
# Phase 2 — Store Many Inconsistent State

## Summary of changes
- Wrapped the Redis pipeline execution and subsequent cache post-processing steps (applying TTL, storing metadata) in a `try/catch` block within the `storeMany()` method of `src/RedisModelService.php`.
- In the event of a failure, `storeMany()` attempts to revert partial writes by invoking the service's `clear()` method, removing any incomplete models, indices, custom indexes, or sorted sets, and then rethrows the original exception.
- Added the unit test `test_store_many_clears_partial_writes_on_pipeline_failure` inside `tests/Unit/RedisModelServiceTest.php` to verify that when pipeline execution throws a RedisException:
  - The cache keys are collected via `scan()`.
  - The `clear()` logic runs, deleting the corresponding hash, meta, index, custom index, and sorted set keys.
  - The original exception is properly rethrown.
- Updated `docs/architecture.md` to document the fallback cleanup mechanism in `storeMany()`.

## Files modified
- [src/RedisModelService.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/src/RedisModelService.php)
- [tests/Unit/RedisModelServiceTest.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/tests/Unit/RedisModelServiceTest.php)
- [docs/architecture.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/architecture.md)
- [docs/reports/phase-2.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/reports/phase-2.md)

## Commands run and outcomes
- `vendor/bin/pint`
  - Status: Passed (styled properly)
- `vendor/bin/phpstan analyse --no-progress`
  - Status: Passed (0 errors)
- `vendor/bin/phpunit`
  - Status: Passed (283 tests, 643 assertions)

## Any follow-ups or caveats
- The `clear()` command inside the `catch` block is wrapped in its own `try/catch` block to swallow secondary cleanup exceptions. This guarantees the original error that triggered the pipeline failure is preserved and correctly bubbles up.

## Commit SHA
02d1eda953ac296c61cd4225c02c1a3b258d5a62
# Phase 3 — Update Attributes Reliability

## Summary of changes
- Strengthened validation in the `updateAttributes()` method of `src/RedisModelService.php` to prevent unknown keys from slipping through during partial model updates.
- Previously, the method relied on an empty model instance check that permitted unknown keys to bypass validation. The validation check has been expanded to test each attribute against:
  - Cached attributes payload keys via `array_key_exists()`.
  - Model's `$fillable` array via `$modelInstance->getFillable()`.
  - Model's `$casts` array via `$modelInstance->getCasts()`.
  - Model's accessors and mutators via `$modelInstance->hasGetMutator()`, `$modelInstance->hasSetMutator()`, and `$modelInstance->hasAttributeMutator()`.
- If an updated attribute is not found in any of the above sources, an `InvalidArgumentException` is thrown.
- Added a comprehensive unit test `test_update_attributes_validation_with_fillable_casts_and_mutators` in `tests/Unit/IncrementalUpdateTest.php` along with a test model helper class `IncrementalUpdateTestModel` to verify validation permits keys present in fillables, casts, mutators, or cache, but correctly rejects invalid fields.
- Updated `docs/features.md` to reflect the updated attribute validation behavior.

## Files modified
- [src/RedisModelService.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/src/RedisModelService.php)
- [tests/Unit/IncrementalUpdateTest.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/tests/Unit/IncrementalUpdateTest.php)
- [docs/features.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/features.md)
- [docs/reports/phase-3.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/reports/phase-3.md)

## Commands run and outcomes
- `vendor/bin/pint`
  - Status: Passed (automatically fixed styling issues)
- `vendor/bin/phpstan analyse --no-progress`
  - Status: Passed (0 errors)
- `vendor/bin/phpunit`
  - Status: Passed (284 tests, 645 assertions)

## Any follow-ups or caveats
- None.

## Commit SHA
2f0ba1268242f5d6597f57e565e732c10ab78aba
# Phase 4 — Revalidate Cache Job Serialization

## Summary of changes
- Modified the constructor of `RevalidateCacheJob` inside `src/Jobs/RevalidateCacheJob.php` to safeguard against closure serialization failures.
- Wrapped the `SerializableClosure` creation in a `try/catch` block and explicitly called `serialize($this->callback)` inside the try block to force validation at dispatch time rather than runtime on the queue.
- If serialization fails, an `InvalidArgumentException` is thrown with a clear and helpful error message advising that the closure captures non-serializable objects or resources.
- Added a unit test `test_revalidate_cache_job_constructor_throws_invalid_argument_exception_on_non_serializable_closure` in `tests/Unit/StaleWhileRevalidateTest.php` to verify the constructor throws `InvalidArgumentException` when an anonymous class (non-serializable) is captured by the closure.
- Updated `docs/features.md` and `README.md` to add prominent warning notes about SWR closure serialization constraints.

## Files modified
- [src/Jobs/RevalidateCacheJob.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/src/Jobs/RevalidateCacheJob.php)
- [tests/Unit/StaleWhileRevalidateTest.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/tests/Unit/StaleWhileRevalidateTest.php)
- [docs/features.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/features.md)
- [README.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/README.md)
- [docs/reports/phase-4.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/reports/phase-4.md)

## Commands run and outcomes
- `vendor/bin/pint --test`
  - Status: Passed (0 styling issues)
- `vendor/bin/phpstan analyse --no-progress`
  - Status: Passed (0 errors)
- `vendor/bin/phpunit`
  - Status: Passed (285 tests, 647 assertions)

## Any follow-ups or caveats
- Developers using SWR must avoid passing closures capturing variables that hold resource descriptors, DB connections, or instances of classes that explicitly forbid serialization.

## Commit SHA
7c47356c6b33f02d6de56e1d1564589fc791f44b
# Phase 5 — Collect Keys By Pattern Detection

## Summary of changes
- Modified the `collectKeysByPattern()` method in `src/RedisModelService.php` to improve compatibility and safety when detecting Redis clients.
- Replaced the string class-name `is_a($this->redis, 'Predis\Client')` check with a direct `instanceof \Redis || instanceof \RedisCluster` check. This checks for `phpredis` first and avoids class-name string comparisons which can fail or cause issues.
- Ensured any other client types fallback to Predis-compatible `scan` interface execution.

## Files modified
- [src/RedisModelService.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/src/RedisModelService.php)
- [docs/reports/phase-5.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/reports/phase-5.md)

## Commands run and outcomes
- `vendor/bin/pint --test`
  - Status: Passed (0 issues)
- `vendor/bin/phpstan analyse --no-progress`
  - Status: Passed (0 errors)
- `vendor/bin/phpunit`
  - Status: Passed (285 tests, 647 assertions)

## Any follow-ups or caveats
- None.

## Commit SHA
8018b41013c9d4cd73b356f3b38634b63dc71393
# Phase 6 — SWR Dispatch Flooding

## Summary of changes
- Implemented SWR dispatch lock inside `rememberAll()` in `src/RedisModelService.php` to prevent concurrent requests from flooding the queue with multiple background revalidation jobs when the cache becomes stale.
- Added `getPrefix(): string` public method to `RedisModelService` exposing the service prefix for SWR lock resolution.
- Updated `RevalidateCacheJob::handle()` in `src/Jobs/RevalidateCacheJob.php` to release/delete the SWR lock key `{$prefix}:swr:lock` upon successful execution of the revalidation process.
- Updated `test_concurrent_requests_during_stale_period` inside `tests/Feature/StaleWhileRevalidateIntegrationTest.php` to expect exactly 1 revalidation job instead of 3 due to SWR lock deduplication.
- Added `test_swr_prevents_duplicate_dispatches_using_lock` inside `tests/Unit/StaleWhileRevalidateTest.php` to thoroughly test dispatch deduplication lock and release flows.
- Updated `docs/features.md` to document SWR Dispatch Deduplication under the SWR section.

## Files modified
- [src/RedisModelService.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/src/RedisModelService.php)
- [src/Jobs/RevalidateCacheJob.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/src/Jobs/RevalidateCacheJob.php)
- [tests/Unit/StaleWhileRevalidateTest.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/tests/Unit/StaleWhileRevalidateTest.php)
- [tests/Feature/StaleWhileRevalidateIntegrationTest.php](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/tests/Feature/StaleWhileRevalidateIntegrationTest.php)
- [docs/features.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/features.md)
- [docs/reports/phase-6.md](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/reports/phase-6.md)

## Commands run and outcomes
- `vendor/bin/pint --test`
  - Status: Passed (0 styling issues)
- `vendor/bin/phpstan analyse --no-progress`
  - Status: Passed (0 errors)
- `vendor/bin/phpunit`
  - Status: Passed (286 tests, 650 assertions)

## Any follow-ups or caveats
- None.

## Commit SHA
0f0d2cb96dff2e10c1724dc950394a759909ff19
# Phase 7 — Stale Sorted-Set Entries

## Summary of changes
- Added `computeStaleZremKeysFromData(Model $model, array $oldData): array<int,string>` to `RedisModelService` which mirrors `computeStaleIndexKeysFromData()`. It compares the old score and the current score using `extractScore()`, returning the sorted set key if they differ.
- Updated `storeMany()` to build a parallel `$staleZremMap` by calling `computeStaleZremKeysFromData()`.
- Updated `storeModel()` signature to accept `?array $precomputedStaleZremKeys = null` and passed this to `storeModelAtomic()`.
- Updated `storeModelAtomic()` signature to accept `?array $precomputedStaleZremKeys = null`. Replaced `$staleZrem = []` with `$staleZrem = $precomputedStaleZremKeys ?? $this->computeStaleZremKeysFromData($model, [])`.
- Added a new unit test `test_stale_zrem_occurs_on_sorted_field_update` in `tests/Unit/RedisModelServiceTest.php` to verify that `storeMany()` appropriately calls ZREM on the sorted key of an old entry when its scored field changes.
- Updated `docs/architecture.md` to note that stale sorted-set cleanup happens via the atomic Lua path.

## Files modified
- `src/RedisModelService.php`
- `tests/Unit/RedisModelServiceTest.php`
- `docs/architecture.md`
- `docs/reports/phase-7.md`

## Commands run and outcomes
- `vendor/bin/pint --test` (Passed after auto-formatting issues fixed)
- `vendor/bin/phpstan analyse --no-progress` (Passed after adding docblock hint for `deserialize` return array)
- `vendor/bin/phpunit` (Passed all tests)

## Any follow-ups or caveats
- Stale sorted-set cleanup happens automatically in `storeMany()` and when the `evaluateLuaOrPipeline` evaluates the `self::LUA_ATOMIC_STORE` Lua script.

## Commit SHA
322866173f44d31d22c57068d9038e9073752738
# Phase 8 — Deprecated Redis Property

## Summary of changes
- Changed `public mixed $redis` visibility to `protected mixed $redis` in `src/RedisBaseService.php`.
- Removed the `@deprecated` docblock line entirely on the `$redis` property of `RedisBaseService`.
- Found and replaced all external usages of `->redis` on the service with the getter `->getRedis()` in all unit/integration tests and workbench setup files:
  - `tests/Feature/StaleWhileRevalidateIntegrationTest.php`
  - `tests/Integration/BasicLifecycleIntegrationTest.php`
  - `tests/Integration/FailureScenarioIntegrationTest.php`
  - `tests/Integration/StampedeProtectionIntegrationTest.php`
  - `tests/Integration/TtlExpiryIntegrationTest.php`
  - `tests/Unit/IncrementalUpdateIndexTest.php`
  - `tests/Unit/IncrementalUpdateTest.php`
  - `tests/Unit/RedisModelServiceTest.php`
  - `tests/Unit/StaleWhileRevalidateTest.php`
  - `workbench/app/Models/User.php`
  - `workbench/database/seeders/DatabaseSeeder.php`

## Files modified
- `src/RedisBaseService.php`
- `tests/Feature/StaleWhileRevalidateIntegrationTest.php`
- `tests/Integration/BasicLifecycleIntegrationTest.php`
- `tests/Integration/FailureScenarioIntegrationTest.php`
- `tests/Integration/StampedeProtectionIntegrationTest.php`
- `tests/Integration/TtlExpiryIntegrationTest.php`
- `tests/Unit/IncrementalUpdateIndexTest.php`
- `tests/Unit/IncrementalUpdateTest.php`
- `tests/Unit/RedisModelServiceTest.php`
- `tests/Unit/StaleWhileRevalidateTest.php`
- `workbench/app/Models/User.php`
- `workbench/database/seeders/DatabaseSeeder.php`

## Commands run and outcomes
- `vendor/bin/pint --test` (Passed)
- `vendor/bin/phpstan analyse --no-progress` (Passed)
- `vendor/bin/phpunit` (Passed)

## Commit SHA
322866173f44d31d22c57068d9038e9073752738
# Phase 9 — Workbench Namespace Imports

## Summary of changes
- Fixed broken namespace imports in the workbench directory.
- Replaced the incorrect triple-nested namespace import `use Workbench\Workbench\Workbench\Database\Factories\UserFactory;` with the correct import `use Workbench\Database\Factories\UserFactory;` in:
  - `workbench/app/Models/User.php`
  - `workbench/database/seeders/DatabaseSeeder.php`

## Files modified
- `workbench/app/Models/User.php`
- `workbench/database/seeders/DatabaseSeeder.php`

## Commands run and outcomes
- `vendor/bin/pint --test` (Passed)
- `vendor/bin/phpstan analyse --no-progress` (Passed)
- `vendor/bin/phpunit` (Passed)

## Commit SHA
322866173f44d31d22c57068d9038e9073752738
# Phase 10 — Benchmark Bootstrap Fix

## Summary of changes
- Investigated `benchmarks/bootstrap.php` to verify the configuration initialization order.
- Confirmed that the Redis cache database connection (`database.redis.cache`) and the package configuration values (`redis-model-cache.*`) are initialized *before* registering `RedisModelCacheServiceProvider` via `$app->register()`.
- Validated that the benchmark scripts run successfully.
- Cross-phase alignment: In [Phase 1](file:///home/sm-me/Codes/Laravel/laravel-redis-model-cache/docs/reports/phase-1.md), configuration validation was hardened to convert `InvalidArgumentException` into warning logs rather than throwing blocking exceptions. The correct bootstrap configuration order, combined with the Phase 1 warning-fallback guard, prevents any boot crash during benchmark executions.

## Files modified
- None (verification phase — configuration order was correct, and benchmarks run successfully).

## Commands run and outcomes
- `php benchmarks/throughput_benchmark.php --scale=100` (Passed)
- `vendor/bin/pint --test` (Passed)
- `vendor/bin/phpstan analyse --no-progress` (Passed)
- `vendor/bin/phpunit` (Passed)

## Commit SHA
c0063b80aa72d830bc24b6c0f27059e02d794555 (Using previous commit since no code changes were needed)
# Phase 11 — PHPDoc Throws Annotations

## Summary of changes
- Conducted public contract hygiene by adding accurate `@throws` annotations to public methods that throw exceptions in the interface and implementation classes.
- Updated `src/Contracts/ModelCacheService.php` to add `@throws` annotations to:
  - `all()` — `@throws \BadMethodCallException`
  - `where()` — `@throws \InvalidArgumentException`
  - `rememberAll()` — `@throws \BadMethodCallException`, `@throws \InvalidArgumentException`
  - `remember()` — `@throws \InvalidArgumentException`
  - `whereIn()` — `@throws \InvalidArgumentException`
  - `whereBetween()` — `@throws \InvalidArgumentException`
  - `orWhere()` — `@throws \InvalidArgumentException`
  - `pluck()` — `@throws \InvalidArgumentException`
  - `selective()` — `@throws \InvalidArgumentException`
  - `first()` — `@throws \InvalidArgumentException`
  - `count()` — `@throws \InvalidArgumentException`
  - `exists()` — `@throws \InvalidArgumentException`
- Updated `src/RedisModelService.php` to add missing implementation `@throws` annotations to:
  - `__construct()` — `@throws \InvalidArgumentException`
  - `rememberAll()` — `@throws \BadMethodCallException`, `@throws \InvalidArgumentException`
  - `selective()` — `@throws \InvalidArgumentException`
  - `first()` — `@throws \InvalidArgumentException`
  - `count()` — `@throws \InvalidArgumentException`
  - `exists()` — `@throws \InvalidArgumentException`

## Files modified
- `src/Contracts/ModelCacheService.php`
- `src/RedisModelService.php`

## Commands run and outcomes
- `vendor/bin/pint --test` (Passed)
- `vendor/bin/phpstan analyse --no-progress` (Passed)
- `vendor/bin/phpunit` (Passed)

## Commit SHA
9076360454e3639e1b67fb88f21930c76ec15243
# Phase 12 — Config Version Key

## Summary of changes
- Introduced a configuration lifecycle guard by adding a `config_version` key to track configuration schema drift.
- Modified `config/redis-model-cache.php` to define `'config_version' => '2.5'` as the first key in the returned array.
- Updated `validateConfiguration()` in `src/RedisModelCacheServiceProvider.php` to check the version key and emit a `Log::warning()` if it is absent or does not match `'2.5'`.
- Added test coverage in `tests/Unit/ServiceProviderTest.php` verifying:
  - The configuration contains the `'config_version'` key.
  - Service provider emits a warning log during boot if a mismatched config version is present.
- Updated `docs/configuration.md` to document the new `config_version` parameter and provide re-publish instructions (`php artisan vendor:publish --tag=redis-model-cache-config --force`).

## Files modified
- `config/redis-model-cache.php`
- `src/RedisModelCacheServiceProvider.php`
- `tests/Unit/ServiceProviderTest.php`
- `docs/configuration.md`

## Commands run and outcomes
- `vendor/bin/pint --test` (Passed)
- `vendor/bin/phpstan analyse --no-progress` (Passed)
- `vendor/bin/phpunit` (Passed)

## Commit SHA
af897a4106d278bc11c4ef64d0ef5cb5034d9686
