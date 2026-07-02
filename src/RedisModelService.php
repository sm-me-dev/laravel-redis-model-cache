<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache;

use BadMethodCallException;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Sm_mE\RedisModelCache\Contracts\ModelCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;

class RedisModelService extends RedisBaseService implements ModelCacheService
{
    protected string $model_class;

    protected array $custom_indexes = [];

    protected string $prefix;

    protected array $indexes = [];

    protected array $sorted = [];

    protected ModelMatchStrategy $matchStrategy;

    public function __construct(
        RedisConnectionResolver $connectionResolver,
        string $model_class,
        array $indexes = [],
        array $sorted = [],
        array $custom_indexes = [],
        ?int $ttl = null,
        ?ModelMatchStrategy $matchStrategy = null
    ) {
        parent::__construct($connectionResolver, $ttl);

        if (! is_subclass_of($model_class, Model::class)) {
            throw new InvalidArgumentException('$model_class must extend '.Model::class);
        }

        $this->model_class = $model_class;
        $this->prefix = (new $model_class)->getTable();
        $this->indexes = $indexes;
        $this->sorted = $sorted;
        $this->custom_indexes = $custom_indexes;
        $this->matchStrategy = $matchStrategy ?? app(ModelMatchStrategy::class);
    }

    public function custom(string $name): Collection
    {
        return $this->hydrateIds($this->redis->smembers($this->customIndexKey($name)));
    }

    /**
     * @param  array<int, string>  $ids
     * @return Collection<int, Model>
     */
    protected function hydrateIds(array $ids, bool $hydrate = true): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $pipeline = $this->redis->pipeline();

        foreach ($ids as $id) {
            $pipeline->hget($this->hashKey(), $id);
        }

        $results = $pipeline->execute();

        if (! $hydrate) {
            return collect($results)->filter()->keys()->values();
        }

        return collect($results)
            ->filter()
            ->map(fn (string $payload): Model => $this->hydrateModelFromPayload($this->deserialize($payload)))
            ->values();
    }

    /**
     * Reconstructs a Model from stored payload including eager-loaded relations.
     *
     * @param  array{attributes: array, relations: array}  $payload
     */
    protected function hydrateModelFromPayload(array $payload): Model
    {
        $model = (new $this->model_class)->newFromBuilder($payload['attributes'] ?? []);

        if (! empty($payload['relations'])) {
            $this->restoreRelations($model, $payload['relations']);
        }

        return $model;
    }

    protected function hashKey(): string
    {
        return "{$this->prefix}:hash";
    }

    protected function deserialize(string $json): array
    {
        return (array) $this->deserializeResult($json);
    }

    public function customIndexKey(string $name): string
    {
        return "{$this->prefix}:custom:{$name}";
    }

    public function customWhere(array $names): Collection
    {
        $keys = array_map(
            fn (string $name): string => $this->customIndexKey($name),
            $names
        );

        return $this->hydrateIds($this->redis->sinter(...$keys));
    }

    public function paginateSorted(string $field, int $page, int $perPage): Collection
    {
        $start = ($page - 1) * $perPage;
        $end = $start + $perPage - 1;

        return $this->sorted($field, $start, $end);
    }

    public function sorted(string $field, int $start, int $end): Collection
    {
        return $this->hydrateIds($this->redis->zrevrange($this->sortedKey($field), $start, $end));
    }

    protected function sortedKey(string $field): string
    {
        return "{$this->prefix}:sorted:{$field}";
    }

    public function delete(int|string $id): void
    {
        $old = $this->redis->hget($this->hashKey(), (string) $id);

        if (! $old) {
            return;
        }

        $oldData = $this->deserialize($old);

        $this->redis->hdel($this->hashKey(), (string) $id);

        $this->removeIndexes($id, $oldData);
        $this->removeSorted($id);
    }

    protected function removeIndexes(int|string $id, array $oldData): void
    {
        // Support both old format (attributes only) and new format (with relations key)
        $attributes = $oldData['attributes'] ?? $oldData;

        foreach ($this->indexes as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $this->redis->srem($this->indexKey($field, $attributes[$field]), (string) $id);
        }
    }

    protected function indexKey(string $field, string|int $value): string
    {
        return "{$this->prefix}:index:{$field}:{$value}";
    }

    protected function removeSorted(int|string $id): void
    {
        foreach ($this->sorted as $field) {
            $this->redis->zrem($this->sortedKey($field), (string) $id);
        }
    }

    public function clearAll(): void
    {
        $keys = $this->collectKeysByPattern("{$this->prefix}:*");

        if ($keys !== []) {
            $this->redis->del(...$keys);
        }
    }

    public function clear(): void
    {
        $keys = [$this->hashKey()];

        foreach ($this->indexes as $field) {
            $keys = array_merge($keys, $this->collectKeysByPattern("{$this->prefix}:index:{$field}:*"));
        }

        foreach ($this->sorted as $field) {
            $keys[] = $this->sortedKey($field);
        }

        foreach (array_keys($this->custom_indexes) as $name) {
            $keys[] = $this->customIndexKey((string) $name);
        }

        $keys = array_values(array_unique(array_filter($keys)));

        if ($keys !== []) {
            $this->redis->del(...$keys);
        }
    }

    public function rememberAll(
        callable $callback,
        bool $hydrate = true,
        array $where = [],
        bool $refresh = false,
        ?array $only = null
    ): Collection {
        $hashExists = (bool) $this->redis->exists($this->hashKey());

        if ($hashExists && ! $refresh) {
            if ($where === []) {
                throw new BadMethodCallException(
                    'Global unindexed cache fetches via rememberAll() are prohibited '
                    .'for memory safety. Provide a $where clause with indexed fields or use a specialized index method.'
                );
            }

            $result = $this->where($where, hydrate: $hydrate, only: $only);
            if ($result->isNotEmpty()) {
                return $result;
            }
        }

        $models = collect($callback());
        $this->storeMany($models);
        $models = $this->filterModelsByKey($models, $only);

        if ($where !== []) {
            return $this->where($where, hydrate: $hydrate, only: $only);
        }

        return $hydrate ? $models : $models->pluck($this->keyName());
    }

    /**
     * @throws BadMethodCallException Full hash scans are prohibited for memory safety.
     */
    public function all(bool $hydrate = true, ?array $only = null): Collection
    {
        throw new BadMethodCallException(
            'all() is disabled. Use where() with indexed fields, rememberIndex(), or customWhere(). '
            .'Full hash scans are prohibited for memory safety.'
        );
    }

    protected function filterRedisHashItemsByKey(array $items, ?array $only = null): array
    {
        return $only === null || $only === []
            ? $items
            : Arr::only($items, $only);
    }

    protected function storeMany(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $pipeline = $this->redis->pipeline();

        foreach ($models as $model) {
            $this->storeModel($model, $pipeline);
        }

        $pipeline->execute();
        $this->applyTTL($this->hashKey());
    }

    protected function filterModelsByKey(Collection $models, ?array $only = null): Collection
    {
        if ($only === null || $only === []) {
            return $models;
        }

        return $models->filter(
            fn (Model $model): bool => in_array($model->getKey(), $only, true)
        )->values();
    }

    protected function keyName(): string
    {
        return (new $this->model_class)->getKeyName();
    }

    /**
     * Query by indexed fields only using set intersection (SINTER).
     *
     * @param  array<string, mixed>  $where  Equality conditions only (field => value)
     * @return Collection<int, Model>
     *
     * @throws InvalidArgumentException If any field is not indexed
     */
    public function where(array $where, bool $hydrate = true, ?array $only = null): Collection
    {
        // Validate ALL query fields are indexed
        foreach (array_keys($where) as $field) {
            if (! in_array($field, $this->indexes, true)) {
                throw new InvalidArgumentException(
                    "Field '{$field}' is not indexed. Define it in \$indexes constructor arg. "
                    .'Available: ['.implode(', ', $this->indexes).']'
                );
            }
        }

        // Build index keys for each equality condition
        $indexKeys = [];
        foreach ($where as $field => $value) {
            $indexKeys[] = $this->indexKey($field, $value);
        }

        // Set intersection = AND logic
        $ids = $indexKeys === [] ? [] : $this->redis->sinter(...$indexKeys);

        // Optional $only filter (primary keys)
        if ($only !== null && $only !== []) {
            $ids = array_values(array_intersect($ids, $only));
        }

        // Batch hydrate (relation-aware)
        return $ids === [] ? collect() : $this->hydrateIds($ids, $hydrate);
    }

    /**
     * Serialize a single model with eager-loaded relations.
     */
    protected function storeModel(Model $model, $pipeline = null): void
    {
        $client = $pipeline ?? $this->redis;
        $key = (string) $model->getKey();

        // Structured payload: attributes + eager-loaded relations
        $payload = [
            'attributes' => $model->getAttributes(),
            'relations' => $this->extractRelations($model),
        ];

        $client->hset($this->hashKey(), $key, $this->serializeResult($payload));

        $this->storeIndexes($model, $pipeline);
        $this->storeSorted($model, $pipeline);
    }

    /**
     * Recursively extracts eager-loaded relations into a serializable structure.
     *
     * @return array<string, array|null> // relationName => serialized relation data
     */
    protected function extractRelations(Model $model): array
    {
        $relations = [];

        foreach ($model->getRelations() as $name => $relation) {
            if ($relation instanceof Collection) {
                // HasMany, MorphMany, BelongsToMany
                $relations[$name] = $relation->map(function (Model $related): array {
                    return $this->serializeModel($related);
                })->toArray();

            } elseif ($relation instanceof Model) {
                // HasOne, BelongsTo, MorphOne, MorphTo
                $relations[$name] = $this->serializeModel($relation);

            } elseif ($relation === null) {
                // Explicitly loaded null relation (e.g., BelongsTo with no parent)
                $relations[$name] = null;
            }
            // Unloaded relations are NOT in getRelations() — correctly omitted
        }

        return $relations;
    }

    /**
     * Serializes a single model (attributes + nested relations).
     *
     * @return array{class: string, attributes: array, relations: array}
     */
    protected function serializeModel(Model $model): array
    {
        return [
            'class' => get_class($model),
            'attributes' => $model->getAttributes(),
            'relations' => $this->extractRelations($model),  // Recursive
        ];
    }

    /**
     * Restores eager-loaded relations onto a model instance.
     *
     * @param  array<string, array|null>  $relations  // Same structure as extractRelations()
     */
    protected function restoreRelations(Model $model, array $relations): void
    {
        foreach ($relations as $name => $relationData) {
            if ($relationData === null) {
                $model->setRelation($name, null);

                continue;
            }

            if (isset($relationData[0]['class'])) {
                // Collection relation (HasMany, MorphMany, BelongsToMany)
                $collection = collect($relationData)->map(function (array $item): Model {
                    return $this->hydrateRelatedModel($item);
                });
                $model->setRelation($name, $collection);

            } else {
                // Single model relation (BelongsTo, HasOne, MorphOne, MorphTo)
                $model->setRelation($name, $this->hydrateRelatedModel($relationData));
            }
        }
    }

    /**
     * @param  array{class: string, attributes: array, relations: array}  $data
     */
    protected function hydrateRelatedModel(array $data): Model
    {
        $model = new $data['class'];
        $model->setRawAttributes($data['attributes'], true);

        if (! empty($data['relations'])) {
            $this->restoreRelations($model, $data['relations']);
        }

        return $model;
    }

    protected function storeIndexes(Model $model, $pipeline = null): void
    {
        $client = $pipeline ?? $this->redis;

        foreach ($this->indexes as $field) {
            $value = $model->{$field};
            if ($value === null) {
                continue;
            }

            $client->sadd($this->indexKey($field, $value), (string) $model->getKey());
        }
    }

    protected function storeSorted(Model $model, $pipeline = null): void
    {
        $client = $pipeline ?? $this->redis;

        foreach ($this->sorted as $field) {
            $value = $model->{$field};
            if ($value === null) {
                continue;
            }

            $score = is_numeric($value)
                ? (float) $value
                : (float) (strtotime((string) $value) ?: 0);

            $client->zadd($this->sortedKey($field), $score, (string) $model->getKey());
        }
    }

    /**
     * Index-first lookup. Throws if field is not indexed.
     *
     * @throws InvalidArgumentException If findBy field is not indexed
     */
    public function remember(
        callable $callback,
        bool $refresh = false,
        string|Expression|null $findBy = null,
        mixed $findValue = null,
        string $findOperator = '='
    ): ?Model {
        // Fast path: if findBy is indexed AND not refresh, try index lookup
        if (! $refresh && $findBy !== null && $this->isIndexed($findBy)) {
            $fieldName = $this->resolveFieldName($findBy);
            $result = $this->findByIndex($fieldName, $findValue, $findOperator);

            if ($result !== null) {
                return $result;
            }
        }

        // Cache miss or non-indexed lookup: execute callback
        $models = collect($callback());

        if ($models->isEmpty()) {
            return null;
        }

        $this->storeMany($models);

        // Post-store lookup (guaranteed to hit index if indexed)
        if ($findBy !== null && $this->isIndexed($findBy)) {
            $fieldName = $this->resolveFieldName($findBy);

            return $this->findByIndex($fieldName, $findValue, $findOperator);
        }

        // Non-indexed findBy: THROW per requirement
        throw new InvalidArgumentException(
            "Field '{$findBy}' is not indexed. Cannot perform lookup without index. "
            .'Add to $indexes or use where()/rememberIndex().'
        );
    }

    protected function isIndexed(string|Expression $field): bool
    {
        if ($field instanceof Expression) {
            return false;  // Expressions cannot be indexed
        }

        return in_array($field, $this->indexes, true);
    }

    protected function resolveFieldName(string|Expression $field): string
    {
        if ($field instanceof Expression) {
            $grammar = (new $this->model_class)->newQuery()->getGrammar();
            $value = $field->getValue($grammar);
            preg_match_all('/(\w+)/', (string) $value, $matches);
            $fields = array_intersect($matches[1], array_keys((new $this->model_class)->getAttributes()));

            return $fields[0] ?? '';
        }

        return $field;
    }

    /**
     * Index-driven single model lookup (equality only).
     */
    protected function findByIndex(string $field, mixed $value, string $operator): ?Model
    {
        // Only equality supported for index lookups
        if ($operator !== '=') {
            return null;
        }

        $key = $this->indexKey($field, $value);
        $ids = $this->redis->smembers($key);

        if ($ids === []) {
            return null;
        }

        // Take first match (should be unique for PK lookups)
        $models = $this->hydrateIds($ids, true);

        return $models->first() ?? null;
    }

    public function rememberIndex(string $field, string|int $value, callable $callback, bool $hydrate = true): Collection
    {
        $key = $this->indexKey($field, $value);

        if ($this->redis->exists($key)) {
            $ids = $this->redis->smembers($key);

            return $hydrate ? $this->hydrateIds($ids) : collect($ids);
        }

        $models = collect($callback());

        foreach ($models as $model) {
            $this->storeModel($model);
            $this->redis->sadd($key, (string) $model->getKey());
        }

        $this->applyTTL($key);

        return $hydrate ? $models : $models->pluck($this->keyName());
    }

    public function rememberCustom(
        string $name,
        callable $callback,
        bool $hydrate = true,
        ?string $sortBy = null,
        bool $refresh = false
    ): Collection {
        $key = $this->customIndexKey($name);
        $sortedKey = $sortBy ? $this->sortedCustomKey($name, $sortBy) : null;
        $lookupKey = $sortedKey ?? $key;

        if ($this->redis->exists($lookupKey) && ! $refresh) {
            $ids = $sortBy ? $this->redis->zrange($sortedKey, 0, -1) : $this->redis->smembers($key);

            return $hydrate ? $this->hydrateIds($ids) : collect($ids);
        }

        if ($refresh) {
            $this->redis->del(...array_filter([$key, $sortedKey]));
        }

        $models = collect($callback());

        foreach ($models as $model) {
            $this->storeModel($model);

            if ($sortBy) {
                $rawScore = $model->{$sortBy};
                $score = is_numeric($rawScore)
                    ? (float) $rawScore
                    : (float) (strtotime((string) $rawScore) ?: 0);

                $this->redis->zadd($sortedKey, $score, (string) $model->getKey());
            } else {
                $this->redis->sadd($key, (string) $model->getKey());
            }
        }

        $this->applyTTL($key);

        if ($sortedKey) {
            $this->applyTTL($sortedKey);
        }

        return $hydrate ? $models : $models->pluck($this->keyName());
    }

    protected function sortedCustomKey(string $custom, string $field): string
    {
        return $this->customIndexKey($custom).":sorted:{$field}";
    }

    /**
     * @return array<int, string>
     *
     * @throws RuntimeException If SCAN command is not available
     */
    protected function collectKeysByPattern(string $pattern): array
    {
        $count = (int) config('redis-model-cache.scan_count', 1000);
        $keys = [];

        if (is_a($this->redis, 'Predis\Client')) {
            // FIXED: Predis returns [$newCursor, $keys] tuple, not flat array
            $cursor = '0';
            do {
                $result = $this->redis->scan($cursor, ['match' => $pattern, 'count' => $count]);
                $cursor = (string) ($result[0] ?? '0');
                $chunk = $result[1] ?? [];
                if (! empty($chunk)) {
                    $keys = array_merge($keys, $chunk);
                }
            } while ($cursor !== '0');

            return array_values(array_unique($keys));
        }

        if (method_exists($this->redis, 'scan')) {
            // phpredis returns already unpacked result
            $iterator = null;
            do {
                $chunk = $this->redis->scan($iterator, $pattern, $count);
                if (is_array($chunk)) {
                    $keys = array_merge($keys, $chunk);
                }
            } while ($iterator !== 0 && $iterator !== '0' && $iterator !== null);

            return array_values(array_unique($keys));
        }

        throw new RuntimeException(
            'SCAN command is not available. The Redis client must support SCAN for production use. '
            .'Ensure phpredis extension is installed or use Predis.'
        );
    }
}
