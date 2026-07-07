# Refactor Notes — Pass 2

## Changes

### 1. DebugCommand: Alias for consistent naming
**File:** `src/Console/DebugCommand.php`
**Change:** Added `redis-model-cache:debug` as an alias via constructor `setAliases()`.
**Rationale:** Three commands used 3 different prefixes (`redis-cache:`, `redis:`, `redis-model-cache:`). This is the first step toward consistent `redis-model-cache:*` naming. The old `redis-cache:debug` still works.
**API impact:** None (additive, backward compatible).

### 2. `selective()` → delegates to `pluck()`
**File:** `src/RedisModelService.php`
**Change:** `selective()` now delegates entirely to `pluck()` (identical signature: `array $fields/attributes, array $where, ?array $only`).
**Rationale:** The two methods had identical implementation, violating DRY. Any bug fix to the HMGET batching or deserialization logic now applies in one place.
**API impact:** None. Both methods remain public with identical behavior.

### 3. `formatBytes()` extracted to shared helper
**Files:** `src/Support/helpers.php`, `src/Console/DebugCommand.php`, `src/Console/MonitorCacheCommand.php`, `src/Console/WarmupCommand.php`
**Change:** Added global `formatBytes()` in `helpers.php`. All three commands now delegate to it.
**Rationale:** Eliminated 3 copies of the same utility.
**API impact:** None (private method bodies changed; public behavior identical).

## Verification
Command: `vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && vendor/bin/phpunit`
