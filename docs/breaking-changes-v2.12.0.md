# Breaking Changes Report — v2.12.0

**Verdict: No breaking changes.**

This release is fully backwards-compatible with v2.10.x and v2.11.x.

## Assessment

| Area | Change | Breaking? |
|------|--------|-----------|
| Public API | No method signatures changed | No |
| Event classes | New events added (`CacheWrite`, `ModelCacheInvalidated`, etc.) | No — new events only, existing events unchanged |
| Configuration | New key added (`max_pipeline_size`) with safe default | No — default 5,000 preserves existing behavior |
| Config validation | Stricter validation for compression, invalidation, redis_failure keys | **No** — new validation only catches values that were already misconfigured; previously these were silently accepted, now they log a warning |
| Internal storage format | No changes to hash payload, index keys, or Lua scripts | No |
| PHP support | Drops PHP < 8.3 | **Minor** — PHP 8.1/8.2 users must upgrade. This change was made in v2.9.0 |
| Laravel support | Drops Laravel < 11 | **Minor** — Laravel 9/10 users must upgrade. This change was made in v2.9.0 |

## Migration Path

### v2.9.x → v2.12.0

No code changes needed. Re-publish config:

```bash
php artisan vendor:publish --tag=redis-model-cache-config --force
```

### v2.8.x → v2.12.0

1. Ensure PHP ≥ 8.3 and Laravel ≥ 11
2. Re-publish config
3. Replace any `selective()` calls with `pluck()`

### Pre-v2.8.0

See the v2.8.0 release notes for cumulative breaking changes (RedisKeyBuilder, CAS safety, etc.).
