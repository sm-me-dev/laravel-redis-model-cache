# Pull Request Template

## Description

<!-- Describe the change and why it's needed. Link to any related issues. -->

## Type of Change

- [ ] Bug fix
- [ ] New feature
- [ ] Performance improvement
- [ ] Documentation
- [ ] Refactoring (no functional change)
- [ ] CI / Infra

## Checklist

- [ ] I have read [CONTRIBUTING.md](CONTRIBUTING.md)
- [ ] Tests pass: `vendor/bin/phpunit`
- [ ] PHPStan passes at level 8: `vendor/bin/phpstan analyse --no-progress`
- [ ] Pint passes: `vendor/bin/pint --test`
- [ ] No `KEYS` commands introduced — uses `SCAN`
- [ ] No hidden O(N) logic introduced
- [ ] Public API changes are reflected in `ARCHITECTURE.md` (frozen API surface)
- [ ] CHANGELOG.md updated

## Public API Impact

<!--
Does this change modify any public interface (contract, trait, config, event)?
If yes, describe the migration path.
-->

## Performance Considerations

<!--
- How many Redis round trips does the new code add?
- Is there a pipeline or batching strategy?
- What happens under 10K model batch? 100K?
-->
