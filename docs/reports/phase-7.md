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
