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
   git tag -a v1.2.0 -m "v1.2.0"
   ```

2. **Push the tag:**
   ```bash
   git push origin v1.2.0
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

- **v1.1.0** — Memory-safe Redis model cache with indexed queries, eager-relation hydration, pipeline atomicity
- **v1.2.0** — Stampede protection, explain mode, metrics & events
- **v1.3.0** — Stale-while-revalidate, incremental updates
- **v1.4.0** — Query engine enhancements (whereIn, whereBetween, orWhere, pluck)
- **v1.5.0** — Background warmup, compression (gzip/zstd/lz4), multi-tenant namespacing
- **v1.6.0** — Lua script atomicity, stampede CAS, atomic store (current)
