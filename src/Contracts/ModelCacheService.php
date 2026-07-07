<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Contracts;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sm_mE\RedisModelCache\Support\ExplainResult;

/**
 * @template TKey of array-key
 * @template TModel of Model
 */
interface ModelCacheService
{
    /**
     * @deprecated 3.1.0 all() is permanently disabled for memory safety.
     *             Use where() with indexed fields, rememberIndex(), or customWhere() instead.
     *
     * @param  array<string>|null  $only
     * @return Collection<int, Model>
     */
    public function all(bool $hydrate = true, ?array $only = null): Collection;

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string>|null  $only
     * @return Collection<int, Model>|ExplainResult
     */
    public function where(array $where, bool $hydrate = true, ?array $only = null): Collection|ExplainResult;

    /**
     * @param  callable(): Collection<int, Model>  $callback
     * @param  array<string, mixed>  $where
     * @param  array<string>|null  $only
     * @return Collection<int, Model>
     */
    public function rememberAll(callable $callback, bool $hydrate = true, array $where = [], bool $refresh = false, ?array $only = null): Collection;

    /**
     * @param  callable(): (Collection<int, Model>|Model|null)  $callback
     */
    public function remember(callable $callback, bool $refresh = false, string|Expression|null $findBy = null, mixed $findValue = null, string $findOperator = '='): ?Model;

    /**
     * @param  callable(): Collection<int, Model>  $callback
     * @return Collection<int, Model>
     */
    public function rememberIndex(string $field, string|int $value, callable $callback, bool $hydrate = true): Collection;

    /**
     * @param  callable(): Collection<int, Model>  $callback
     * @return Collection<int, Model>
     */
    public function rememberCustom(string $name, callable $callback, bool $hydrate = true, ?string $sortBy = null, bool $refresh = false): Collection;

    public function delete(int|string $id): void;

    /**
     * Update a single attribute on a cached model without full serialization.
     *
     * @param  int|string  $id  Model primary key
     * @param  string  $attribute  Attribute name to update
     * @param  mixed  $value  New value for the attribute
     *
     * @throws \InvalidArgumentException If model not found in cache or attribute doesn't exist
     */
    public function updateAttribute(int|string $id, string $attribute, mixed $value): void;

    /**
     * Update multiple attributes on a cached model without full serialization.
     *
     * @param  int|string  $id  Model primary key
     * @param  array<string, mixed>  $attributes  Attributes to update (attribute => value)
     *
     * @throws \InvalidArgumentException If model not found in cache or any attribute doesn't exist
     */
    public function updateAttributes(int|string $id, array $attributes): void;

    /**
     * Query models where field value is in the given array (OR logic).
     *
     * @param  string  $field  The indexed field to query
     * @param  array<int|string>  $values  Array of values to match
     * @param  bool  $hydrate  Whether to return full models or just IDs
     * @param  array<string>|null  $only  Optional filter for specific primary keys
     * @return Collection<int, Model>|ExplainResult
     */
    public function whereIn(string $field, array $values, bool $hydrate = true, ?array $only = null): Collection|ExplainResult;

    /**
     * Query models where field value is between min and max (range query).
     *
     * @param  string  $field  The sorted field to query
     * @param  int|float  $min  Minimum value (inclusive)
     * @param  int|float  $max  Maximum value (inclusive)
     * @param  bool  $hydrate  Whether to return full models or just IDs
     * @param  array<string>|null  $only  Optional filter for specific primary keys
     * @return Collection<int, Model>|ExplainResult
     */
    public function whereBetween(string $field, int|float $min, int|float $max, bool $hydrate = true, ?array $only = null): Collection|ExplainResult;

    /**
     * Add OR condition to query by combining results.
     *
     * @param  array<string, mixed>  $where  Additional WHERE conditions (OR logic)
     * @param  array<string>  $baseIds  IDs from previous where() call
     * @param  bool  $hydrate  Whether to return full models or just IDs
     * @return Collection<int, Model>
     */
    public function orWhere(array $where, array $baseIds = [], bool $hydrate = true): Collection;

    /**
     * Fetch models with only specific attributes (partial hydration).
     *
     * @param  array<string>  $attributes  Attribute names to retrieve
     * @param  array<string, mixed>  $where  WHERE conditions (indexed fields)
     * @param  array<string>|null  $only  Optional filter for specific primary keys
     * @return Collection<int, array<string, mixed>> Collection of associative arrays
     */
    public function pluck(array $attributes, array $where = [], ?array $only = null): Collection;

    /**
     * @deprecated 3.1.0 Use pluck() instead. selective() will be removed in a future release.
     *
     * Single HMGET round-trip (no pipeline), avoids full model hydration.
     * 60-80% less memory than full model hydration.
     *
     * @param  array<string>  $fields  Field names to retrieve
     * @param  array<string, mixed>  $where  WHERE conditions (indexed fields)
     * @param  array<string>|null  $only  Optional filter for specific primary keys
     * @return Collection<int, array<string, mixed>>
     */
    public function selective(array $fields, array $where = [], ?array $only = null): Collection;

    public function clear(): void;

    public function clearAll(): void;

    /**
     * Enable debug mode - logs all Redis operations with timing and data sizes.
     *
     * @return $this
     */
    public function debug(): static;

    /**
     * Inspect a cached model by ID - shows all Redis keys and data for a given model.
     *
     * @param  int|string  $id  The model primary key
     * @return array<string, mixed>|null Null if model not found in cache
     */
    public function inspect(int|string $id): ?array;

    /**
     * Analyze all indexes for this model and return a cardinality report.
     *
     * @return array<string, mixed>
     */
    public function analyzeIndexes(): array;

    /**
     * Find a single model by its primary key.
     *
     * @param  int|string  $id  Model primary key
     */
    public function find(int|string $id): ?Model;

    /**
     * Return the first model matching the where clause.
     *
     * @param  array<string, mixed>  $where  Equality conditions (field => value)
     */
    public function first(array $where): ?Model;

    /**
     * Count models matching the where clause.
     *
     * Uses SCARD for single-index queries (O(1)).
     * Uses SINTER + count for multi-index queries (O(N)).
     *
     * @param  array<string, mixed>  $where  Equality conditions (field => value)
     */
    public function count(array $where): int;

    /**
     * Check if any models match the where clause.
     *
     * Uses EXISTS for single-index queries (O(1)).
     * Uses SINTER + check for multi-index queries (O(N)).
     *
     * @param  array<string, mixed>  $where  Equality conditions (field => value)
     */
    public function exists(array $where): bool;
}
