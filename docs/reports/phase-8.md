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
