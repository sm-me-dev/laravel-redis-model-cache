# ADR-0003: No Automatic Index Generation

**Date:** 2026-07-06
**Status:** Accepted

## Context

Some caching systems automatically create indexes based on observed query patterns. This reduces configuration overhead but introduces non-deterministic behavior: the set of available indexes depends on prior query history, which varies by environment.

## Decision

Indexes must be explicitly declared. The package does not:

1. Inspect query patterns to automatically create index sets.
2. Suggest indexes based on WHERE clauses.
3. Create wildcard or catch-all indexes.

If a query references an undeclared field, the result is an `InvalidArgumentException` — not a silent index creation.

## Consequences

- **Positive:** Predictable Redis memory usage; no surprise index creation in production; clear configuration.
- **Negative:** More upfront configuration; users must anticipate their query patterns.
- **Neutral:** Documentation and examples help users configure correctly.

## Alternatives Considered

1. **Auto-create index on first query** — Rejected: first query would need an HSCAN to build the index, which is exactly what we prohibit. Also non-deterministic.
2. **Wildcard index on all model fields** — Rejected: would double memory for every model stored.
3. **Log warning instead of throwing** — Rejected: makes unindexed queries silently expensive.
