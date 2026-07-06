# ADR-0002: Deterministic Behavior

**Date:** 2026-07-06
**Status:** Accepted

## Context

Caching systems often introduce non-determinism through TTL-based eventual consistency, probabilistic early expiration, or adaptive behavior. This makes production debugging and performance prediction difficult.

## Decision

The package prioritizes deterministic behavior over "smart" or adaptive behavior:

1. **Index resolution is deterministic:** Given the same where clauses and index configuration, the same Redis commands execute in the same order every time.
2. **Invalidation is deterministic:** Every model lifecycle event triggers explicit, traceable index cleanup steps. No TTL-based "eventually consistent" cleanup.
3. **Explain mode returns a deterministic plan:** `explain()->where(...)` returns the exact commands that will execute, without running them.
4. **No automatic optimization:** The query planner does not reorder index intersection order based on estimated cardinality — that would make behavior depend on Redis state.

## Consequences

- **Positive:** Predictable performance; reproducible debugging; explain mode is trustworthy.
- **Negative:** Misses opportunities for adaptive optimization (e.g., always intersecting in declared order even if a different order would be faster).
- **Neutral:** Trade-off accepted for production predictability.

## Alternatives Considered

1. **Adaptive index ordering** — Rejected: non-deterministic, makes explain mode unreliable.
2. **TTL-only cleanup** — Rejected: stale index entries would accumulate until TTL expiry.
3. **Probabilistic early expiration** — Rejected: adds unpredictable latency spikes.
