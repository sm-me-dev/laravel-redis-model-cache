# Static Analysis Policy

The package runs PHPStan at its maximum configured level (`level: max`) over
`src/` and the model fixtures in `tests/Fixtures/`.

The baseline contains only stable, identifier-based findings from dynamic
Redis client behavior. The configuration keeps three categories of narrow
exceptions:

- optional Telescope and Pulse classes, which are not runtime dependencies;
- Redis client command results and deserialized cache payloads, whose types are
  determined at runtime;
- framework dispatch helpers and test doubles that expose dynamic methods.

New source code should narrow Redis responses and configuration values at the
boundary where they enter the package. Broadening an ignore pattern or adding
a baseline entry is not an acceptable substitute for a source-level fix.

Run the check with:

```bash
vendor/bin/phpstan analyse --no-progress
```
