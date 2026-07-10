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
