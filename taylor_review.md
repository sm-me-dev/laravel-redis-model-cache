# Taylor Otwell Review — laravel-redis-model-cache

*Simulated review of the package as if evaluating for Laravel News or first-party sponsorship.*

---

## Critical (Blocking)

### 1. Static State in Traits — Octane Hazard
**File:** `src/Concerns/HasRedisModelCache.php:20-28`

```php
protected static array $redisModelCacheProcessing = [];
protected static array $redisModelCacheDeletedInCycle = [];
```

Class-level static arrays in traits are the #1 source of Octane bugs. The `flushRedisModelCacheProcessing()` cleanup relies on `App::terminating()` firing and `WorkerTickStarting` event existing. Neither is guaranteed in all Octane configurations.

**Why this matters to Laravel developers:** Any Octane user who applies this trait gets unpredictable behavior under load. Static state bleeds between requests when lifecycle hooks are missed. This alone makes the package a hard "no" for production Octane deployments.

**Fix:** Use `ScopedSingleton` (Laravel 11+) or request-scoped services instead of static arrays.

### 2. Three Different Command Prefixes
**Files:** Three commands with `redis-cache:`, `redis:`, `redis-model-cache:` prefixes.

This is the kind of inconsistency that makes a package feel amateur. Every command from the same package should share a single prefix. Laravel's own packages use `cache:`, `queue:`, `make:` — always consistent.

**Fix:** Unify to `redis-model-cache:*`. Keep old names as aliases for one version.

---

## Major (Should Fix Before Public Endorsement)

### 3. Config Method Returns Mixed (14 PHPStan Suppressions)
**File:** `phpstan.neon.dist:16-34`

```yaml
- '~Cannot access offset [a-zA-Z_]+ on mixed~'
- '~ expects .*, mixed given\.~'
- '~function array_map expects~'
# ... 11 more patterns
```

14 suppresssed error patterns because every `config('redis-model-cache.*')` call returns `mixed`. This is a code smell that obscures real type issues. For a Laravel package, this should be a typed `DTO` wrapping `Config`, not raw `config()` calls scattered across 10 files.

### 4. `all()` Throws at Runtime Instead of Being Removed
**File:** `src/RedisModelService.php:613-618`

```php
public function all(bool $hydrate = true, ?array $only = null): Collection
{
    throw new BadMethodCallException('all() is disabled...');
}
```

A method that always throws is a design smell. If `all()` is never valid, don't define it. Every IDE autocomplete and developer expectation becomes a trap. Laravel developers expect `Model::all()` to work everywhere.

**Fix:** Remove the method. If it must exist for interface compatibility, make it `@internal` with a clear deprecation message, not a runtime exception.

### 5. Missing CI/CD Pipeline
No `.github/workflows/ci.yml` exists. For a package claiming "Production Ready" with a tests badge in the README, there must be a CI workflow. The badge links to `actions/workflows/run-tests.yml` which doesn't exist in the repo.

**Fix:** Add `.github/workflows/ci.yml` with PHP 8.3/8.4 × Laravel 11/12 matrix, Redis service, and run pint + phpstan + pest.

---

## Minor (Nice-to-Have)

### 6. `phpstan-baseline.neon` Exists But Isn't Ideal
The baseline suppresses issues that should be fixed. For a well-typed package, the baseline should be empty.

### 7. `selective()` and `pluck()` Are Identical
Two public methods with identical signatures and implementations. This confuses users and doubles maintenance. The refactor this review helped apply (delegating `selective()` to `pluck()`) is good — consider deprecating `selective()` in the next minor.

### 8. `formatBytes()` Duplicated 3 Times
(Now fixed in this pass.) A shared helper should have been extracted from day one.

### 9. `composer.json` Missing Useful Metadata
- No `homepage` URL
- No `support` section (issues, source)
- `description` is good but could mention "hash-based" for discoverability
- No `funding` entries

---

## Approved Items

- **Strict type declarations** throughout — excellent
- **PHPStan level max** with near-clean pass — excellent  
- **Deterministic behavior** (no DB fallback, no KEYS) — correct design choice
- **Comprehensive README** with "Why This Exists" section — rare and appreciated
- **Stampede protection** with CAS Lua scripts — well-implemented
- **Multi-tenant isolation** via `{tenant:{id}:{table}}` prefix — clean design
- **Observability events** with Telescope/Pulse integration — thoughtful
- **CHANGELOG, CONTRIBUTING, SECURITY, LICENSE** all present — professional

## Verdict

**Conditionally approve** — the core idea (hash-based model cache with index-driven queries) is sound and well-executed. The deterministic, no-fallback approach is the right design. However, the static state in the trait and the inconsistent command naming are significant quality signals that prevent endorsement.

**To reach "Laravel News recommended" quality, fix:**
1. Static trait state → scoped service
2. Command prefix consistency  
3. Config DTO or typed accessor (to eliminate PHPStan suppressions)
4. CI pipeline
