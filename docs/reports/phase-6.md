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
11cc19f69aade3cc45d0880a73cadb1156563c72
