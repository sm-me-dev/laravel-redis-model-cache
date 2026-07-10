<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

/**
 * Centralized Redis key builder.
 *
 * All keys for a single model table (or tenant+table) share the same Redis
 * Cluster hash tag, ensuring every Lua and multi-key operation is
 * CROSSSLOT-safe.
 *
 * Default mode  : `{table}`
 * Tenant mode   : `{tenant:<sanitizedTenantId>:<table>}`
 *
 * All key-building methods derive from a single `tag()` call so the hash-slot
 * assignment is guaranteed to be consistent across the entire package.
 */
final class RedisKeyBuilder
{
    /** The resolved hash tag including braces, e.g. `{users}` */
    private readonly string $tag;

    /**
     * @param  string  $table  Model table name
     * @param  string|null  $tenantId  Sanitized tenant identifier (or null for single-tenant)
     */
    public function __construct(
        private readonly string $table,
        private readonly ?string $tenantId = null,
    ) {
        $this->tag = $tenantId !== null
            ? '{tenant:'.$tenantId.':'.$table.'}'
            : '{'.$table.'}';
    }

    /**
     * Build a RedisKeyBuilder from a table name and optional raw tenant ID.
     *
     * @param  string|int|null  $rawTenantId  Raw value from the resolver; will be sanitized
     */
    public static function for(string $table, string|int|null $rawTenantId = null): self
    {
        if ($rawTenantId === null || $rawTenantId === '') {
            return new self($table);
        }

        $sanitized = self::sanitizeTenantId((string) $rawTenantId);

        return new self($table, $sanitized);
    }

    /**
     * Sanitize a raw tenant ID so `{`, `}`, and `:` cannot corrupt the hash-tag layout.
     *
     * Input  `{bad:}id` → output `bad__id`
     */
    public static function sanitizeTenantId(string $raw): string
    {
        return str_replace(['{', '}', ':'], ['', '', '_'], $raw);
    }

    /**
     * The full hash tag shared by every key in this namespace.
     *
     * e.g. `{users}` or `{tenant:42:users}`
     */
    public function tag(): string
    {
        return $this->tag;
    }

    /**
     * Redis hash key that stores all model payloads as fields.
     *
     * Pattern: `{table}:hash`
     */
    public function hashKey(): string
    {
        return $this->tag.':hash';
    }

    /**
     * Meta hash key that stores `cached_at`, `_last_invalidated_at`, `version`, etc.
     *
     * Pattern: `{table}:meta`
     */
    public function metaKey(): string
    {
        return $this->tag.':meta';
    }

    /**
     * Stampede / rebuild lock key.
     *
     * Pattern: `{table}:lock:<suffix>`
     */
    public function lockKey(string $suffix = 'stampede'): string
    {
        return $this->tag.':lock:'.$suffix;
    }

    /**
     * SWR (Stale-While-Revalidate) lock key.
     *
     * Pattern: `{table}:lock:swr`
     */
    public function swrLockKey(): string
    {
        return $this->lockKey('swr');
    }

    /**
     * Regular (equality) index set key.
     *
     * Pattern: `{table}:index:<name>:<value>`
     */
    public function indexKey(string $name, string|int $value): string
    {
        return $this->tag.':index:'.$name.':'.$value;
    }

    /**
     * Sorted-set index key.
     *
     * Pattern: `{table}:sorted:<name>`
     */
    public function sortedKey(string $name): string
    {
        return $this->tag.':sorted:'.$name;
    }

    /**
     * Custom index set key.
     *
     * Pattern: `{table}:custom:<name>`
     */
    public function customIndexKey(string $name): string
    {
        return $this->tag.':custom:'.$name;
    }

    /**
     * Custom sorted-set key (sorted view of a custom index).
     *
     * Pattern: `{table}:custom:<indexName>:sorted:<field>`
     */
    public function sortedCustomKey(string $indexName, string $field): string
    {
        return $this->customIndexKey($indexName).':sorted:'.$field;
    }

    /**
     * Table name (without braces).
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * Tenant ID (sanitized), or null in single-tenant mode.
     */
    public function tenantId(): ?string
    {
        return $this->tenantId;
    }
}
