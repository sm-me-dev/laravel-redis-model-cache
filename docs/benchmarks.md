# Benchmarks

Tested with PHP 8.4, Laravel 12, Redis 7.4, phpredis (localhost).

## Write Throughput

| Batch Size | Models/s | Per Model |
|-----------|----------|-----------|
| 10 | 9,869/s | 0.101 ms |
| 100 | 14,344/s | 0.070 ms |
| 1,000 | 17,677/s | 0.057 ms |
| 5,000 | 21,381/s | 0.047 ms |

Incremental updates: `updateAttribute()` ~5× faster than full store.

## Read Throughput

| Operation | Queries/s |
|-----------|-----------|
| Index lookup `where(role_id)` | 284/s |
| Sorted range `whereBetween(score)` | 744/s |
| Partial hydration `pluck(['id','name'])` | 510/s |

## Latency (P50/P95/P99)

| Operation | P50 | P95 | P99 |
|-----------|-----|-----|-----|
| Index read | 1.32 ms | 3.74 ms | 5.26 ms |
| Sorted read | 0.60 ms | 2.18 ms | 3.61 ms |
| Batch write (10) | 0.78 ms | 4.37 ms | 6.73 ms |
| pluck vs hydrate | pluck 50.6% faster |

## Memory

| Scale | Hash Size | Index Overhead | Compressed (gzip) |
|-------|-----------|----------------|-------------------|
| 100 | 25 KB | 0.59 KB | 16 KB (-36%) |
| 1,000 | 278 KB | 4.71 KB | 160 KB (-42%) |
| 5,000 | 1.73 MB | 24 KB | 695 KB (-57%) |

## Running Locally

```bash
php benchmarks/throughput_benchmark.php --scale=1000
php benchmarks/latency_benchmark.php
php benchmarks/memory_benchmark.php
```
