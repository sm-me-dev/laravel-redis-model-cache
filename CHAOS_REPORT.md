# Chaos Resilience Report

**Package:** `sm-me/laravel-redis-model-cache`  
**Version:** 2.6.0  
**Scope:** Production infrastructure failure simulation  
**Date:** 2026-07-11

## Summary

All 8 chaos-resilience tests pass (8/8). The package demonstrates safe behavior under simulated Redis restart, lock expiry, network corruption, and concurrent external modification scenarios. No silent data corruption, no indefinite blocking, and no unsafe lock release occurs under any simulated failure.

## Test Scenarios

### 1. Redis Restart — Lua Script Cache Flush

| Test | Status | Key Finding |
|------|--------|-------------|
| `test_lua_script_cache_flush_falls_back_to_eval` | ✅ | After `SCRIPT FLUSH`, `store()` falls back to EVAL, successfully stores, indexes, and retrieves data |
| `test_lua_script_cache_flush_during_batch_store` | ✅ | `storeMany()` recovers from flushed script cache via EVAL fallback in pipeline mode |

**Mechanism:** `executeLua()` attempts EVALSHA first. On NOSCRIPT (Redis response or exception), it falls back to EVAL which re-loads the script. The SHA is cached via reference for subsequent fast-path calls.

**Simulated failure:** Redis restart, server-side script cache eviction, or `SCRIPT FLUSH` from operational tooling.

### 2. Lock TTL Auto-Release

| Test | Status | Key Finding |
|------|--------|-------------|
| `test_stampede_lock_auto_releases_via_ttl` | ✅ | When a lock holder crashes without releasing, the lock auto-expires via its TTL. A new process can acquire immediately after expiry. |
| `test_stampede_lock_with_cas_release_external_expiry` | ✅ | CAS release is never used if lock is already gone — safe no-op path. If the lock is held by another process, CAS prevents accidental deletion. |

**Mechanism:** Locks use `SET NX EX <timeout>`. TTL is the sole release mechanism when Lua is disabled. When Lua is enabled, CAS (`LUA_LOCK_CAS`) safely deletes only the current holder's lock; on any failure (value mismatch, Lua error, key not found), it returns false without falling back to blind DEL.

**Simulated failure:** Process crash mid-operation, network partition separating lock holder from Redis.

### 3. SWR Freshness Guard — Stale Write Prevention

| Test | Status | Key Finding |
|------|--------|-------------|
| `test_swr_freshness_guard_prevents_stale_overwrite` | ✅ | When `_last_invalidated_at > revalidationToken`, the Lua atomic store script returns 0 and skips the write. Stale revalidation data never overwrites newer model state. |
| `test_swr_freshness_guard_allows_fresh_writes` | ✅ | When `revalidationToken > _last_invalidated_at`, the write proceeds normally. |

**Mechanism:** `LUA_ATOMIC_STORE` compares `ARGV[4]` (revalidation timestamp) against `_last_invalidated_at` in the meta hash. If an invalidation occurred *after* the revalidation was dispatched, the Lua script immediately returns 0 — the model data and all index keys remain untouched.

**Simulated failure:** Delayed queue job executing a revalidation after the model was saved again in production.

### 4. External Cache Modification

| Test | Status | Key Finding |
|------|--------|-------------|
| `test_key_consistency_after_external_hash_modification` | ✅ | Corrupted hash entries return `null` on `find()`. Index lookups silently skip corrupted entries. |
| `test_key_consistency_after_external_key_deletion` | ✅ | If the hash key is deleted externally, `where()` returns empty. Re-storeing rebuilds the hash and indexes correctly. |

**Mechanism:** `find()` uses `HGET` + `json_decode`. On corruption or invalid JSON, it returns `null` without throwing. `where()` resolves via SMEMBERS/SINTER and hydrates via HMGET — entries with corrupted/unparseable JSON are silently skipped in the collection.

## Resilience Posture

| Scenario | Behavior | Risk |
|----------|----------|------|
| Lua unavailable | Falls back to pipeline commands | None |
| NOSCRIPT on EVALSHA | Falls back to EVAL (re-loads script) | None |
| Lock holder crashes | Lock auto-expires via TTL | None (TTL-based safety) |
| CAS lock value mismatch | Returns false, no blind DEL | None |
| External key deletion | Graceful empty results, recoverable | None |
| Corrupted payload in hash | Returns null / skipped silently | Low — no crash, but data is silently missing |
| Network partition during write | Exception propagates per ADR-0004 | Low — no silent data loss |
| Pipeline partial write failure | `storeMany()` calls `clear()` to roll back | None |
| SWR revalidation after mutation | Lua freshness guard skips stale write | None |

## Monitoring Recommendations

See [README.md — Enterprise Deployment](#enterprise-deployment) for monitoring configuration.

## Running the Tests

```bash
# All tests (requires Redis on localhost:6379)
vendor/bin/phpunit

# Chaos tests only
vendor/bin/phpunit tests/Integration/ChaosResilienceIntegrationTest.php

# Unit chaos tests (mocked, no Redis required)
vendor/bin/phpunit tests/Unit/EdgeCaseTest.php
```
