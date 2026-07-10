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
