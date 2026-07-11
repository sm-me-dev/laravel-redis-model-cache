# Release Notes — v2.12.0

**Release date:** 2026-07-11  
**Target:** Production  

## What's New

### Performance & Scalability (Phase 7)

**Internal Pipeline Chunking** — `storeMany()` now automatically splits batches exceeding `max_pipeline_size` (default 5,000) into sequential pipeline flushes. Prevents OOM crashes when storing 50K+ records in a single call. Throughput remains at ~27K models/sec at the sweet spot.

**New `max_pipeline_size` config** — controls the internal pipeline threshold. Reduce to 2,000 if memory-constrained.

**Benchmark suite expanded** — 3 new benchmark scripts covering scalability (100→50K records), batch size comparison (Lua vs Pipeline), and concurrency workloads (read-heavy, write-heavy, balanced).

### Test Coverage & CI Hardening (Phase 6)

- **18 new tests** — concurrent write/race conditions (6), Octane lifecycle/Observability reset (6), SWR edge cases (3), ObservabilitySubscriber handlers (4)
- **CI hardening** — `composer audit`, PCOV coverage with Codecov upload, JUnit logging

### Configuration Validation (Phase 5)

- Strict validation for: `hydrate_batch_size`, `compression.algorithm`/`.level`/`.min_size`, `multi_tenant.strategy`/`.key`, `invalidation.strategy`/`.queue`, `redis_failure.strategy`
- 11 new validation tests

### Observability & Metrics (Phase 4)

- New events: `CacheWrite`, `ModelCacheInvalidated`, `RedisConnectionFailed`, `CacheOperationFailed`
- New counters: writes, invalidations, failures with overflow-safe normalization
- Observability snapshot includes all new counters

### Redis Failure Handling (Phase 3)

- Three failure strategies: `exception` (default), `log`, `fallback`
- Configurable log channel and fallback callback
- Event dispatch for failure observability

## Compatibility

| Component | Version |
|-----------|---------|
| PHP | 8.3, 8.4 |
| Laravel | 11, 12, 13 |
| Redis | ≥ 6.0 (≥ 7.0 recommended) |
| phpredis | ≥ 5.3 |
| Orchestra Testbench | ^9.0, ^10.11, ^11.0 |

## Key Metrics

| Check | Status |
|-------|--------|
| Tests | 352 pass (2304 assertions) |
| PHPStan | Level 8, 0 errors |
| Pint | Passed |
| Composer audit | No advisories |
| Write throughput (batch 5K) | 27,340 models/sec |
| Read throughput (1K results) | 368 qps |

## Full Changelog

See [CHANGELOG.md](../CHANGELOG.md) for the complete history.
