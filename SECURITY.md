# Security Policy

## Supported Versions

| Version | Supported |
|---|---|
| v2.x | ✅ |
| v1.x | ⚠️ Critical fixes only |
| < 1.0 | ❌ |

## Reporting a Vulnerability

If you discover a security vulnerability in this package, please **do not** open a public GitHub issue.

Instead, email the maintainer directly or open a [GitHub Security Advisory](https://github.com/sm-me/laravel-redis-model-cache/security/advisories/new).

Please include:

- A clear description of the vulnerability
- Steps to reproduce
- PHP and Laravel versions tested against
- Redis version if applicable

You should receive a response within 48 hours. If the issue is confirmed, a fix will be released and published under a [Security Advisory](https://github.com/sm-me/laravel-redis-model-cache/security/advisories).

## Scope

This package handles cached Eloquent model data and Redis keys. The following are in scope:

- Unauthorized cross-tenant data access (multi-tenant isolation bypass)
- Redis key injection via tenant IDs
- Cache poisoning via serialized payload manipulation
- Information disclosure through cache metadata
- Denial of service through unindexed queries or KEYS commands

The following are out of scope:

- Redis server security (auth, TLS, network access)
- Laravel framework vulnerabilities
- PHP unserialization (the package uses JSON serialization, not PHP serialize)

## Security Considerations for Operators

### Multi-Tenant Mode

When `multi_tenant.enabled` is `true`, the tenant ID from the resolver is used in Redis key prefixes. Ensure the resolver sanitizes user-controlled input (the `RequestTenantResolver` strips `{`, `}`, and `:` from tenant IDs). If using a custom resolver, apply similar sanitization.

### Redis Connection Security

Use a dedicated Redis database or namespace for model cache data. The package uses the `cache` connection by default — ensure this connection uses appropriate auth credentials and network isolation.

### Compression

The package supports gzip, zstd, and lz4 compression. These decompress on read. If an attacker can write arbitrary compressed data to Redis (e.g., through a misconfigured Redis ACL), decompression bombs are possible. Limit Redis access to trusted services only.
