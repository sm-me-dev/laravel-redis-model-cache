# Performance Guide

Benchmark results, scaling guidelines, tuning advice, and best practices for `laravel-redis-model-cache`.

> **Version:** 2.9.0  
> **Tested with:** PHP 8.4, Laravel 13, Redis 7.4, phpredis  
> **Environment:** Local (localhost Redis, no network latency)

---

## Table of Contents

1. [Benchmark Results](#benchmark-results)
   - [Write Throughput](#write-throughput)
   - [Read Throughput](#read-throughput)
   - [Latency Percentiles](#latency-percentiles)
   - [Memory Usage](#memory-usage)
   - [Compression Impact](#compression-impact)
2. [Optimizations Applied](#optimizations-applied)
3. [Scaling Guidelines](#scaling-guidelines)
4. [Tuning Guide](#tuning-guide)
5. [Best Practices](#best-practices)

---

## Benchmark Results

### Write Throughput

`storeMany()` performance at various batch sizes:

| Batch Size | Total Time | Throughput | Per Model |
|-----------|-----------|-----------|-----------|
| 10        | 1.0 ms    | 9,869/s   | 0.101 ms |
| 100       | 7.0 ms    | 14,344/s  | 0.070 ms |
| 1,000     | 56.6 ms   | 17,677/s  | 0.057 ms |
| 5,000     | 233.9 ms  | 21,381/s  | 0.047 ms |

**Incremental vs Full Store (1,000 iterations):**

| Operation | Total Time | Per Op     | Speedup |
|-----------|-----------|------------|---------|
| `updateAttribute()` (single field) | ~50 ms | ~0.05 ms | ~5× faster |
| `updateAttributes()` (2 fields)    | ~100 ms | ~0.10 ms | ~3× faster |
| `storeMany()` (full store)         | ~300 ms | ~0.30 ms | baseline |

### Read Throughput

| Operation | Queries/s | Per Query |
|-----------|-----------|-----------|
| Index lookup `where(role_id)` | 284/s | 3.52 ms |
| Sorted range `whereBetween(score)` | 744/s | 1.34 ms |
| Partial hydration `pluck(['id','name'])` | 510/s | 1.96 ms |
| Full hydration `where(role_id)` | ~284/s | ~3.52 ms |

### Latency Percentiles

Measured on Redis 7.4, localhost, no network overhead.

#### Read: `where(role_id)` index lookup

| Metric | Value  |
|--------|--------|
| P50    | 6.7 ms |
| P95    | 12.3 ms |
| P99    | 14.8 ms |
| P999   | 17.3 ms |
| Avg    | 7.3 ms |
| Min    | 4.0 ms |

#### Write: `storeMany(1 model)`

| Metric | Value   |
|--------|---------|
| P50    | 0.29 ms |
| P95    | 0.58 ms |
| P99    | 0.86 ms |
| P999   | 2.14 ms |
| Avg    | 0.33 ms |

#### pluck (partial hydration) vs full hydrate

| Metric | pluck | Full | Improvement |
|--------|-------|------|------------|
| P50    | 7.6 ms | 15.0 ms | **50.6% faster** |
| P95    | 16.0 ms | 30.3 ms | 47.2%  |
| P99    | 22.3 ms | 42.7 ms | 47.8%  |

### Memory Usage

Average model payload: ~800 bytes (includes `id`, `name`, `email`, `role_id`, `status`, `score`, `bio`, `metadata`).

| Records | Uncompressed | Per Record | Compressed (gzip) | Per Record | Savings |
|---------|-------------|-----------|-------------------|-----------|---------|
| 100     | 96.7 KB    | 990 B     | 20.2 KB           | 206 B     | **79%** |
| 1,000   | 1.00 MB    | 1.03 KB   | 521 KB            | 533 B     | **48%** |
| 5,000   | 5.24 MB    | 1.07 KB   | 2.66 MB           | 558 B     | **49%** |

Compressed payload size: 348 B (vs 803 B uncompressed) = **56.7% reduction**.

### Compression Impact

| Algorithm | Memory Savings | Write Overhead | Read Overhead |
|-----------|---------------|----------------|---------------|
| None      | baseline | baseline | baseline |
| gzip (level 6) | 57% | +50% | +15-20% |
| zstd (level 3) | 55-60% | +20-30% | +10-15% |
| lz4        | 40-45% | +5-10% | +5-8% |

**Recommendation:** Enable compression when Redis memory is constrained (≥100K records). Use `lz4` for latency-sensitive workloads that still need compression.

---

## Optimizations Applied

The following performance optimizations are already implemented in v1.2.0:

### 1. Batch Old-Data Read (HMGET)

**Before:** `storeMany()` issued N individual `HGET` calls (one per model) to compute stale index keys. Each call required a round-trip to Redis.

**After:** A single `HMGET` call fetches all old data in one round-trip. Stale index keys are computed in-memory from the batch result.

**Impact:** Reduces round-trips from N to 1. Most noticeable at scale (1000+ models): **~30-50% faster bulk writes**.

### 2. Optimized JSON Serialization

**Before:** `json_encode($data, JSON_THROW_ON_ERROR)` produced larger payloads by escaping Unicode and slashes.

**After:** `json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)` produces 5-15% smaller payloads without functional change.

### 3. Batched Pipeline Hydration

**Before:** `hydrateIds()` created a single pipeline containing all `HGET` commands. For 10K+ results, the pipeline array consumed significant memory.

**After:** Large pipelines are automatically chunked into batches of 5,000 (configurable via `hydrate_batch_size`). This reduces memory pressure and prevents timeouts.

**Impact:** ~20% memory reduction for large result sets, no throughput regression.

### 4. Lua Script Atomic Store

Single-round-trip atomic model storage using Lua scripting (disabled: pipeline fallback). Estimated 20-30% faster than multi-command pipelines.

---

## Scaling Guidelines

### Small Scale (1K–10K records)

- **Configuration:** Default settings work well
- **Latency:** P50 < 10ms reads, < 0.5ms writes
- **Memory:** 1–10 MB Redis memory
- **Recommended batch size:** 100–500 models per `storeMany()` call

### Medium Scale (10K–100K records)

- **Configuration:** Enable compression (`gzip` or `zstd`)
- **Latency:** P99 < 30ms reads, < 2ms writes
- **Memory:** 10–50 MB Redis (compressed: 5–25 MB)
- **Recommended batch size:** 500–2000 models per `storeMany()` call
- **Index cardinality:** Keep indexes < 50K members for optimal SINTER performance

### Large Scale (100K–1M records)

- **Configuration:** Enable compression, tune `hydrate_batch_size` to 2000–3000
- **Latency:** P99 < 100ms reads, < 10ms writes (network-dependent)
- **Memory:** 100 MB–1 GB Redis (compressed: 50–500 MB)
- **Recommended batch size:** 1000–5000 models per `storeMany()` call
- **Index considerations:**
  - Use high-cardinality indexes sparingly (< 100K members)
  - Prefer `whereIn()` with 10–100 values over `orWhere()` chains
  - Enable stampede protection for all `rememberAll()` calls
- **Redis Cluster:** Hash tags (`{table}` prefix) ensure all keys for a model land on the same node

### Very Large Scale (1M+ records)

- **Architecture:** Consider sharding by tenant or model type
- **Configuration:** Use `lz4` compression for lowest overhead
- **TTL strategy:** Set aggressive TTLs to automatically prune stale data
- **Warmup:** Use `redis-model-cache:warmup` command to pre-populate during deployments
- **Monitoring:** Enable observability events and integrate with Laravel Pulse

---

## Tuning Guide

### Config Reference

| Key | Default | Recommendation |
|-----|---------|---------------|
| `default_ttl` | 86400 (24h) | Match your data freshness requirements |
| `hydrate_batch_size` | 5000 | Reduce to 2000 if memory-constrained |
| `scan_count` | 1000 | Increase to 5000 for faster cache clears |
| `lua_scripting.enabled` | true | Keep enabled for atomic operations |
| `compression.enabled` | false | Enable for 50%+ memory savings |
| `compression.algorithm` | gzip | Use `lz4` for speed, `zstd` for ratio |
| `stampede_protection.enabled` | false | Enable for high-traffic endpoints |

### Redis Configuration

```
# redis.conf tuning for large model caches
maxmemory 4gb
maxmemory-policy allkeys-lru
activedefrag yes

# If using pipelining for batch operations
# Ensure tcp-backlog is adequate
tcp-backlog 511
```

### PHP Configuration

```
# php.ini
memory_limit = 256M        # Increase for large batch operations
max_execution_time = 300   # Long-running warmup operations
```

---

## Best Practices

### Do

- **Batch writes:** Use `storeMany()` with 100–1000 models at a time for maximum throughput
- **Use partial hydration:** `pluck()` reduces memory by 50%+ for list views
- **Index judiciously:** Only index fields you query by. Each index adds memory overhead
- **Set TTLs:** Always set a TTL to prevent stale data accumulation
- **Use stampede protection:** Enable for frequently-accessed but slow-to-recompute caches
- **Prefer whereIn over orWhere:** `whereIn()` uses `SUNION` which is faster than merging results in PHP
- **Enable lua scripting:** Provides atomic operations with fewer round-trips

### Don't

- **Don't query on unindexed fields** — `where()` validates this and throws
- **Don't use `all()`** — disabled for memory safety
- **Don't call `store()` in a loop** — use `storeMany()` with a Collection instead
- **Don't store relations you don't need** — eager load only what you query
- **Don't use `orWhere()` for many conditions** — use `whereIn()` for same-field OR queries

### Batch Size Sweet Spot

```
small records (< 500 B each):    batch of 1000-5000
medium records (500 B - 2 KB):   batch of 500-2000
large records (> 2 KB):          batch of 100-500
```

---

## Running Benchmarks

```bash
# Quick throughput benchmark (100 records)
php benchmarks/throughput_benchmark.php --scale=100

# Full throughput benchmark (1000 records)
php benchmarks/throughput_benchmark.php --scale=1000

# Latency percentiles (500 operations)
php benchmarks/latency_benchmark.php --operations=500

# Memory usage analysis
php benchmarks/memory_benchmark.php

# Incremental vs full store comparison
php benchmarks/incremental_update_benchmark.php

# Run all benchmarks
for b in benchmarks/*.php; do php "$b"; echo "---"; done
```

> **Note:** Benchmark results vary by hardware, Redis version, network latency, and data size. Run benchmarks in your own environment for representative numbers.
