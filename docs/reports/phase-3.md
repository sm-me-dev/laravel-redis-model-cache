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
