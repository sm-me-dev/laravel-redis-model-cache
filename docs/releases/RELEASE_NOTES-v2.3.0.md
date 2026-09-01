# v2.3.0 — Production Polish

Compatibility, correctness, and developer experience improvements ahead of broad production adoption.

## Compatibility

| Change | Before | After |
|--------|--------|-------|
| PHP constraint | `^8.2` | `^8.3` |
| Laravel constraint | `^11.0` | `^11.0 \|\| ^12.0` |
| Testbench constraint | `^9.0` | `^9.0 \|\| ^10.11` |
| CI matrix | PHP 8.2/8.3 × Laravel 11 | PHP 8.3/8.4/8.5 × Laravel 11/12 × prefer-lowest/prefer-stable |

## Changed

- **README claims audit** — removed unsubstantiated "production-tested" and blanket O(1) claims; all performance characteristics now qualified with explicit complexity table and methodology links
- **PHPStan raised to level max** — 121 pre-existing errors baselined with documented `ignoreErrors` for config-derived mixed types, Telescope stubs, and Mockery conventions; new code must meet level max
- **Docs reorganization** — technical docs moved from repository root to `docs/` (`architecture.md`, `invalidation.md`, `performance.md`, `query-limitations.md`, `roadmap.md`, `agents.md`); root now contains only `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `LICENSE`
- **Type improvements** — `RequestTenantResolver::resolveFromAuth()` and `resolveFromSession()` return types narrowed; `CacheManager` config access cast to `int`; `QueryPlanner` mixed string concat cast to `string`; `ResolvedIndex` keys parameter widened to `array`
- **`hydrateIds()`** — early return for empty ID arrays restored (regression in the HMGET batch path)

## Added

- **Architecture diagrams** — 4 SVGs in `docs/diagrams/`: high-level request flow, Redis key layout, query resolution, invalidation lifecycle
- **Architecture Decision Records (ADRs)** — 5 documents in `docs/adr/`: indexed-only queries, deterministic behavior, no automatic index generation, no silent DB fallback, Redis hash/set/sorted set rationale
- **Repository polish** — CodeQL analysis workflow, Dependabot config, Pint standalone workflow, expanded PR template with benchmark/BC/test/docs sections, compatibility and benchmark regression issue templates
- **Benchmark automation** — `scripts/run-benchmarks.sh` runner, `docs/benchmarks/report.md` for results, `benchmarks.yml` CI workflow, standalone `benchmarks/bootstrap.php` bypassing Testbench dependency
- **Edge-case test coverage** — `EdgeCaseTest` with 11 tests covering Redis connection exceptions, corrupted payloads, empty indexes, null cache results, async invalidation behavior, and delete of uncached model

## Fixed

- **Benchmark scripts** — all 4 benchmark scripts now register the service provider via `benchmarks/bootstrap.php` instead of relying on bare workbench bootstrap
- **Internal doc links** — all cross-references from root-level docs updated to `docs/` paths

## Full Changelog

See [CHANGELOG.md](CHANGELOG.md) for the complete list of changes.
