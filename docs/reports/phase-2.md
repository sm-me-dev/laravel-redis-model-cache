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
c37fb23d922535c84906aea8dd1456c0fc3529fa
