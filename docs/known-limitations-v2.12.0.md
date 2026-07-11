# Known Limitations — v2.12.0

## 1. Read Throughput is PHP CPU-Bound

At scale (50K+ records), `where()` queries returning large result sets (5K+ models) spend ~98% of time in PHP deserialization (`json_decode` + model construction), not Redis operations. This is architectural: each stored model is a JSON blob that must be parsed.

**Mitigation:** Use `pluck()` for list views — skips model construction and reduces read time by ~78%.

## 2. No Distributed Lock for SWR Across Workers

The SWR lock (`{table}:lock:swr`) prevents duplicate revalidation jobs within a single process but does **not** coordinate across multiple Laravel workers or servers. Under extreme concurrent traffic, two workers could both detect staleness and dispatch jobs before either acquires the lock.

**Impact:** Rare duplicate revalidation jobs (harmless — stale data is served in both cases). Mitigated by the low probability of simultaneous lock acquisition and the idempotent nature of revalidation.

## 3. Sorted Set TTL is Per-Set, Not Per-Member

`EXPIRE` on a sorted set removes all members. If a sorted set has mixed-age members (e.g., `created_at` timestamps spanning days), the entire set expires at once when the TTL elapses. This can cause a cold-start scenario for sorted range queries.

**Workaround:** Set `ttl` generously (≥ 24h) or disable TTL on sorted sets by omitting sorted fields from the config.

## 4. No Built-in Pagination for Sorted Queries

`whereBetween()` returns all matching results. For very large sorted sets (100K+ members matching a range), this can consume significant memory.

**Workaround:** Use LIMIT via `ZRANGEBYSCORE ... LIMIT offset count` pattern manually: call `$this->redis->zrangebyscore($key, $min, $max, ['limit' => [$offset, $count]])` and pass the resulting IDs to `hydrateIds()`.

## 5. `all()` Is Disabled

`RedisModelService::all()` throws `BadMethodCallException`. This is by design — full hash scans are prohibited for memory safety. Use `where()` with indexed fields or `customWhere()`.

## 6. No Cross-Table Joins

The cache stores individual model tables independently. There is no support for JOINs or cross-table queries. Load related models via Eloquent relationships (which will bypass the cache).

## 7. Multi-Tenant Key Isolation Has Overhead

Each tenant gets its own set of Redis keys (`{tenant:{id}:{table}}:*`). With 1,000+ tenants, the key namespace grows proportionally. SCAN operations for `clear()` iterate across all tenant prefixes, which can be slow.

**Workaround:** Use per-tenant Redis databases or separate Redis instances at very large multi-tenant scale.

## 8. No Automatic Reconnection on Redis Failures

The service uses the Laravel Redis connection as configured. If the Redis server drops and reconnects, the PHP Redis extension handles reconnection transparently for short outages, but prolonged disconnections require application-level retry logic (provided by the `redis_failure.fallback` strategy).

## 9. Compression is CPU-Heavy on Writes

Enabling compression (gzip/zstd) adds 20-50% to write latency. The `compression.min_size` threshold (default 512 B) skips compression for small payloads where the CPU cost outweighs memory savings.

## 10. PHPStan at Level 8 Requires Strict Types

All source files use `declare(strict_types=1)`. If consuming code passes incompatible types, PHPStan will flag them. This is by design but may require adjustments in consuming codebases not using strict types.

## Summary

| Limitation | Severity | Recommended Action |
|------------|----------|--------------------|
| PHP CPU-bound reads | Medium | Use `pluck()` for list views |
| SWR lock contention | Low | Acceptable — revalidation is idempotent |
| Sorted set TTL is per-set | Low | Use generous TTL |
| No sorted query LIMIT | Medium | Manual `ZRANGEBYSCORE ... LIMIT` for large sets |
| `all()` disabled | Low | Use indexed queries instead |
| No cross-table joins | Low | Load relations via Eloquent |
| Multi-tenant key overhead | Medium | Consider per-tenant Redis at scale |
| No auto-reconnection | Low | Use `redis_failure.fallback` strategy |
| Compression CPU cost | Low | `min_size` threshold mitigates |
| Strict types required | Low | Add type casts at call sites if needed |
