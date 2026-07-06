# Contributing

## Development Setup

```bash
git clone <your-fork>
cd laravel-redis-model-cache
composer install
```

## Running Tests

```bash
# All tests
vendor/bin/phpunit

# Specific test file
vendor/bin/phpunit --filter=ConcurrencySafety

# Test with testdox output
vendor/bin/phpunit --testdox
```

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
5. [ ] Public API changes are reflected in the README, ARCHITECTURE.md, and CHANGELOG.md
6. [ ] No `all()` calls or unindexed queries in new code
7. [ ] No `KEYS` commands — always use `SCAN`
8. [ ] New config keys have env var support with defaults

## Code Conventions

- PHP 8.4 constructor property promotion
- Strict types (`declare(strict_types=1)`)
- Named arguments in method calls (preferred)
- Pipelines over individual round trips for batch operations
- Deterministic behavior over "smart" behavior
- Document tradeoffs explicitly
- No hidden O(N) logic

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

See [ARCHITECTURE.md](ARCHITECTURE.md) and [INVALIDATION.md](INVALIDATION.md) for detailed design documentation.
