<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

use InvalidArgumentException;

/**
 * Determines which Redis index keys to use for a given query.
 *
 * Every where() call MUST reference only declared indexes. No fallback
 * to SQL, no wildcard scans, no O(N) hash iteration.
 */
class IndexResolver
{
    /**
     * Resolve which index keys to query for an equality where clause.
     *
     * @param  array<string, mixed>  $where  Field => value pairs (AND logic)
     * @param  array<int, string>  $availableIndexes  Declared index fields
     *
     * @throws InvalidArgumentException If any field is not declared as an index
     */
    public function resolve(array $where, array $availableIndexes): ResolvedIndex
    {
        if ($where === []) {
            throw new InvalidArgumentException(
                'Empty where clause is not allowed. Provide at least one indexed field => value pair.'
            );
        }

        foreach (array_keys($where) as $field) {
            if (! in_array($field, $availableIndexes, true)) {
                throw new InvalidArgumentException(
                    "Field '{$field}' is not indexed. Declare it in \$indexes. "
                    .'Available: ['.implode(', ', $availableIndexes).']'
                );
            }
        }

        $prefixResolver = $this->buildPrefixResolver($where);

        return $this->buildResolvedIndex($where, $prefixResolver);
    }

    /**
     * Resolve which index keys to query for a whereIn clause.
     *
     * @param  string  $field  The indexed field
     * @param  array<int|string>  $values  Values to match (OR logic)
     * @param  array<int, string>  $availableIndexes  Declared index fields
     *
     * @throws InvalidArgumentException If field is not declared as an index
     * @throws InvalidArgumentException If values array is empty
     */
    public function resolveWhereIn(string $field, array $values, array $availableIndexes): ResolvedIndex
    {
        if (! in_array($field, $availableIndexes, true)) {
            throw new InvalidArgumentException(
                "Field '{$field}' is not indexed. Declare it in \$indexes. "
                .'Available: ['.implode(', ', $availableIndexes).']'
            );
        }

        if ($values === []) {
            throw new InvalidArgumentException(
                "Values array cannot be empty for whereIn query on field '{$field}'."
            );
        }

        $keys = array_map(
            fn (string|int $value): string => $this->buildIndexKey($field, $value, '{table}'),
            $values
        );

        $isSingle = count($keys) === 1;
        $command = $isSingle ? 'SMEMBERS' : 'SUNION';
        $strategy = $isSingle ? 'single_key_lookup' : 'union';

        return new ResolvedIndex(
            strategy: $strategy,
            keys: $keys,
            command: $command,
            metadata: [
                'field' => $field,
                'value_count' => count($values),
                'key_count' => count($keys),
            ],
        );
    }

    /**
     * Build a Redis index key path for a given field+value.
     *
     * The {table} placeholder is replaced by the caller with the actual prefix.
     * This keeps the resolver stateless — it only resolves logic, not concrete prefixes.
     */
    public function buildIndexKey(string $field, string|int $value, string $tablePlaceholder): string
    {
        return "{$tablePlaceholder}:index:{$field}:{$value}";
    }

    /**
     * Build a Redis sorted key path for a given field.
     */
    public function buildSortedKey(string $field, string $tablePlaceholder): string
    {
        return "{$tablePlaceholder}:sorted:{$field}";
    }

    /**
     * Build the prefix-resolver callback for a where clause.
     *
     * @param  array<string, mixed>  $where
     */
    private function buildPrefixResolver(array $where): callable
    {
        return function (string $prefix) use ($where): array {
            $keys = [];
            foreach ($where as $field => $value) {
                $keys[] = $this->buildIndexKey($field, $value, $prefix);
            }

            return $keys;
        };
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function buildResolvedIndex(array $where, callable $prefixResolver): ResolvedIndex
    {
        $isSingle = count($where) === 1;
        $command = $isSingle ? 'SMEMBERS' : 'SINTER';
        $strategy = $isSingle ? 'single_key_lookup' : 'intersection';

        return new ResolvedIndex(
            strategy: $strategy,
            keys: [], // Concrete keys require prefix — resolved at query time
            command: $command,
            metadata: [
                'field_count' => count($where),
                'fields' => array_keys($where),
                'single_field' => $isSingle ? array_key_first($where) : null,
                'single_value' => $isSingle ? reset($where) : null,
                'prefix_resolver' => $prefixResolver,
            ],
        );
    }
}
