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
96eae8bbeed05f8af6a256446f04771c1f85cd71
