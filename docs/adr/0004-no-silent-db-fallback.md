# ADR-0004: No Silent Database Fallback

**Date:** 2026-07-06
**Status:** Accepted

## Context

When a cache miss occurs or a query cannot be served from Redis, many caching systems silently fall back to the primary database. While this provides a seamless user experience, it hides performance problems and can cause unexpected database load in production.

## Decision

The package never silently falls back to the database. When a query cannot be served from Redis:

1. **Unindexed fields:** Throw `InvalidArgumentException` — the developer must reconfigure indexes.
2. **Cache miss:** Return empty results or throw, depending on the method. The callback-based methods (`rememberAll`, `remember`) require an explicit callback — the caller must decide when to hit the DB.
3. **Redis connection failure:** Methods propagate the Redis exception. There is no try-catch that falls through to Eloquent.

## Consequences

- **Positive:** Transparent failure modes; no surprise database load; clear separation of concerns.
- **Negative:** Applications that want a DB fallback must implement it themselves (wrapping in try-catch).
- **Neutral:** Consistent with the "deterministic" principle — behavior does not change based on Redis availability.

## Alternatives Considered

1. **Silent DB fallback on unindexed query** — Rejected: hides the indexing problem; makes performance unpredictably slow.
2. **Silent DB fallback on Redis failure** — Rejected: hides infrastructure problems; can cause cascading DB failures.
3. **Configurable fallback behavior** — Rejected: adds complexity; violates principle of least surprise.
