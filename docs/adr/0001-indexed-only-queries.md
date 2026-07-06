# ADR-0001: Indexed-Only Queries

**Date:** 2026-07-06
**Status:** Accepted

## Context

Eloquent models support arbitrary WHERE clauses, but Redis does not support secondary index scanning without declared index structures. Allowing unindexed field queries would require full hash scans (HGETALL or HSCAN), which are O(N) on the entire dataset and can OOM a Redis instance.

## Decision

All query methods (`where()`, `whereIn()`, `orWhere()`, `selective()`, `pluck()`, `first()`, `count()`, `exists()`) require every field to be declared in the `$indexes` array. Fields not declared in indexes cause `InvalidArgumentException` to be thrown.

The `all()` method is permanently disabled. Full hash scans are blocked at the API level.

## Consequences

- **Positive:** Deterministic performance; no accidental O(N) scans; clear contract for users.
- **Negative:** Users must pre-declare all queryable fields; dynamic/ad-hoc queries require a database fallback (which this package intentionally does not provide).
- **Neutral:** Adds configuration overhead per model type.

## Alternatives Considered

1. **Allow any field, scan via HSCAN** — Rejected: O(N) across entire hash, OOM risk.
2. **Allow any field, scan via SCAN with pattern** — Rejected: still O(N), and SCAN may return duplicates.
3. **Silent database fallback** — Rejected: violates the "no silent fallback" principle (see ADR-0004).
4. **Automatic index generation from observed queries** — Rejected: non-deterministic, violates ADR-0003.
