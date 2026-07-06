# ADR-0005: Redis Hash and Set Data Structures

**Date:** 2026-07-06
**Status:** Accepted

## Context

Model data and indexes could be stored in several Redis data structures (strings, hashes, sets, sorted sets, JSON). The choice affects memory efficiency, query speed, atomicity guarantees, and Redis Cluster compatibility.

## Decision

### Primary storage: Redis Hashes
- Model payloads stored as hash fields: `HSET {prefix}:hash {id} {serialized_json}`
- Rationale: Hashes provide O(1) field access (HGET), batch field access (HMGET), and individual field deletion (HDEL). Unlike JSON documents, hashes allow partial updates without reading the entire document.

### Index storage: Redis Sets
- Equality indexes use Redis Sets: `SADD {prefix}:index:{field}:{value} {id}`
- Rationale: Sets provide O(1) membership test, O(N) SMEMBERS, and O(N) SINTER/SUNION. No automatic ordering needed for equality lookups.

### Range queries: Redis Sorted Sets
- Range-indexed fields use Redis Sorted Sets: `ZADD {prefix}:sorted:{field} {score} {id}`
- Rationale: Sorted sets provide O(log N) ZRANGEBYSCORE and O(1) ZSCORE.

### Why not:
- **RedisJSON:** Requires RedisJSON module; reduces portability; no HMGET-style partial reads.
- **Plain String keys per model:** No index support; would require client-side index maintenance.
- **Lists:** No random access; no SINTER/SUNION.
- **HyperLogLog:** Approximate counts only; no member retrieval.

## Consequences

- **Positive:** Familiar Redis patterns; no module dependencies; O(1) access paths.
- **Negative:** Sets consume O(N) memory per indexed value per model; sorted sets add 8 bytes per score.
- **Neutral:** Hash tags `{...}` required for Cluster co-location of related keys.
