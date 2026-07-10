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
ab774f83922628335396818a0e6354ac7dce452d
