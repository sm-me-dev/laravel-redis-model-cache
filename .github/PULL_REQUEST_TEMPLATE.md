# Pull Request

## Description

<!-- Describe the change and why it's needed. Link to any related issues. -->

## Type of Change

- [ ] Bug fix
- [ ] New feature
- [ ] Performance improvement
- [ ] Documentation
- [ ] Refactoring (no functional change)
- [ ] CI / Infra
- [ ] Test addition

## Implementation Summary

<!-- How does this change work? What are the key design decisions? -->

## Benchmark Impact

<!--
- Did you run benchmarks before/after?
- How many Redis round trips does the new code add?
- Is there a pipeline or batching strategy?
- What happens under 10K model batch? 100K?
-->

## Backward Compatibility

<!--
- Does this change modify any public interface (contract, trait, config, event, method signature)?
- If yes, describe the migration path.
- Is there a deprecation period?
-->

## Test Coverage

- [ ] New tests added for this change
- [ ] Existing tests pass: `vendor/bin/phpunit`
- [ ] PHPStan passes: `vendor/bin/phpstan analyse --no-progress`
- [ ] Pint passes: `vendor/bin/pint --test`

## Documentation

- [ ] README updated (if public API changed)
- [ ] CHANGELOG.md updated
- [ ] Architecture docs updated (if data flow changed)

## Checklist

- [ ] No `KEYS` commands introduced — uses `SCAN`
- [ ] No hidden O(N) logic introduced
- [ ] Lua scripts use mathematical offset indexing (not `string.gmatch`)
- [ ] Batch operations prime scripts before pipeline entry
- [ ] Static arrays are bounded (ring buffer or flush hook)
- [ ] ADRs updated if architectural decisions changed
