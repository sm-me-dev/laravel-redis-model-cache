# Release Process

## Checklist

### Pre-Release

- [ ] All tests pass: `vendor/bin/phpunit`
- [ ] Code style passes: `vendor/bin/pint --test`
- [ ] Static analysis passes: `vendor/bin/phpstan analyse`
- [ ] CHANGELOG / release notes updated
- [ ] Version bumped in `README.md` and `RELEASE_NOTES-v*.md`

### Creating a Release

1. **Tag the release:**
   ```bash
   git tag -a v2.0.0 -m "v2.0.0"
   ```

2. **Push the tag:**
   ```bash
   git push origin v2.0.0
   ```

3. **GitHub Actions** will automatically:
   - Run the test suite
   - Create a GitHub Release with auto-generated notes

### Versioning

Follow [Semantic Versioning](https://semver.org/):

- **MAJOR** (x.0.0): Breaking API changes
- **MINOR** (0.x.0): New features, backward compatible
- **PATCH** (0.0.x): Bug fixes, backward compatible

### Packagist

Releases are automatically synced to Packagist via the GitHub webhook on tag pushes.
No manual action required.

## Recent Releases

- **v2.0.0** — Major expansion: stampede protection, SWR, query engine, incremental updates, background warmup, compression, Lua atomicity, observability, Telescope/Pulse integration, benchmarks (current)
- **v1.1.0** — Memory-safe Redis model cache with indexed queries, eager-relation hydration, pipeline atomicity
