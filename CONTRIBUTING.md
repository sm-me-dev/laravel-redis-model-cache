# Contributing

## Development Setup

```bash
git clone <your-fork>
cd laravel-redis-model-cache
composer install
```

## Running Tests

```bash
# All tests (Unit + Feature + Integration)
vendor/bin/phpunit

# Unit tests only (mocked Redis, no external deps)
vendor/bin/phpunit --testsuite=Unit

# Feature tests (use real Redis)
vendor/bin/phpunit --testsuite=Feature

# Integration tests (REQUIRE Redis on 127.0.0.1:6379)
vendor/bin/phpunit --testsuite=Integration

# Specific test file
vendor/bin/phpunit --filter=ConcurrencySafety

# Test with testdox output
vendor/bin/phpunit --testdox
```

### Redis Requirements

Integration and Feature tests require a running Redis server:

```bash
# Start Redis (if not running)
redis-server --daemonize yes

# Verify connection
redis-cli ping
# → PONG
```

Integration tests auto-skip if Redis is unavailable.

## Code Quality

```bash
# Static analysis (PHPStan level 8)
vendor/bin/phpstan analyse --no-progress

# Code style (Pint)
vendor/bin/pint --test    # check only
vendor/bin/pint           # auto-fix
```

## Pull Request Checklist

Before submitting:

1. [ ] New functionality includes tests
2. [ ] All tests pass: `vendor/bin/phpunit`
3. [ ] PHPStan passes at level 8: `vendor/bin/phpstan analyse --no-progress`
4. [ ] Pint passes: `vendor/bin/pint --test`
5. [ ] Public API changes are reflected in the README, docs/architecture.md, and CHANGELOG.md
6. [ ] No `all()` calls or unindexed queries in new code
7. [ ] No `KEYS` commands — always use `SCAN`
8. [ ] New config keys have env var support with defaults
9. [ ] Lua scripts use mathematical offset indexing (no `string.gmatch`)
10. [ ] Batch operations prime scripts before pipeline entry (`primeAtomicStoreScript()`)
11. [ ] Static arrays use bounded ring buffers (not unbounded array append)
12. [ ] Octane lifecycle hooks registered for any new static state

## Code Conventions

- PHP 8.4 constructor property promotion
- Strict types (`declare(strict_types=1)`)
- Named arguments in method calls (preferred)
- Pipelines over individual round trips for batch operations
- Deterministic behavior over "smart" behavior
- Document tradeoffs explicitly
- No hidden O(N) logic
- No unbounded static array growth — always cap with ring buffer or flush hook
- Lua scripts must use ARGV count offsets, never string parsing

## Commit Convention

```
type(scope): description

Types: feat, fix, test, docs, refactor, chore, perf
Scope: core, cache, query, invalidation, obs, docs, ci

Examples:
- feat(core): multi-tenant Redis isolation
- test(cache): concurrency and failure safety suite
- docs: production-grade documentation and API freeze
```

## Architecture

See [docs/architecture.md](docs/architecture.md) and [docs/invalidation.md](docs/invalidation.md) for detailed design documentation.
