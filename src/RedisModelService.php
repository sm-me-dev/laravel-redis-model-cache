<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
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
            throw new InvalidArgumentException('$model_class must extend ' . Model::class);
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

    protected function hydrateIds(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $pipeline = $this->redis->pipeline();

        foreach ($ids as $id) {
            $pipeline->hget($this->hashKey(), $id);
        }

        $results = $pipeline->execute();

        return collect($results)
            ->filter()
            ->map(fn (mixed $item): Model => $this->newModelFromCache($this->deserialize((string) $item)))
            ->values();
    }

    protected function hashKey(): string
    {
        return "{$this->prefix}:hash";
    }

    protected function newModelFromCache(array $attributes): Model
    {
        return (new $this->model_class)->newFromBuilder($attributes);
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
        foreach ($this->indexes as $field) {
            if (! array_key_exists($field, $oldData)) {
                continue;
            }

            $this->redis->srem($this->indexKey($field, $oldData[$field]), (string) $id);
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
            $result = $where === []
                ? $this->all(hydrate: $hydrate, only: $only)
                : $this->where($where, hydrate: $hydrate, only: $only);

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

    public function all(bool $hydrate = true, ?array $only = null): Collection
    {
        $items = $this->filterRedisHashItemsByKey(
            $this->redis->hgetall($this->hashKey()),
            $only
        );

        if (! $hydrate) {
            return collect(array_keys($items));
        }

        return collect($items)
            ->map(fn (string $item): Model => $this->newModelFromCache($this->deserialize($item)))
            ->values();
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

        foreach ($models as $model) {
            $this->storeModel($model);
        }

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

    public function where(array $where, bool $hydrate = true, ?array $only = null): Collection
    {
        $items = $this->filterRedisHashItemsByKey(
            $this->redis->hgetall($this->hashKey()),
            $only
        );

        if (! $hydrate) {
            return collect(array_keys($items))
                ->filter(function (string $key) use ($where, $items): bool {
                    return $this->matchesWhere($this->deserialize($items[$key]), $where);
                })
                ->values();
        }

        return collect($items)
            ->filter(fn (string $item): bool => $this->matchesWhere($this->deserialize($item), $where))
            ->map(fn (string $item): Model => $this->newModelFromCache($this->deserialize($item)))
            ->values();
    }

    protected function matchesWhere(array $item, array $where): bool
    {
        foreach ($where as $field => $value) {
            if (! array_key_exists($field, $item)) {
                return false;
            }

            if (! $this->matchStrategy->matches($item[$field], $value, '=')) {
                return false;
            }
        }

        return true;
    }

    protected function storeModel(Model $model): void
    {
        $key = (string) $model->getKey();
        $data = $model->getAttributes();

        $this->redis->hset(
            $this->hashKey(),
            $key,
            $this->serializeResult($data)
        );

        $this->storeIndexes($model);
        $this->storeSorted($model);
    }

    protected function storeIndexes(Model $model): void
    {
        foreach ($this->indexes as $field) {
            $value = $model->{$field};

            if ($value === null) {
                continue;
            }

            $this->redis->sadd($this->indexKey($field, $value), (string) $model->getKey());
        }
    }

    protected function storeSorted(Model $model): void
    {
        foreach ($this->sorted as $field) {
            $value = $model->{$field};

            if ($value === null) {
                continue;
            }

            $score = is_numeric($value) ? (float) $value : (float) (strtotime((string) $value) ?: 0);
            $this->redis->zadd($this->sortedKey($field), $score, (string) $model->getKey());
        }
    }

    public function remember(
        callable $callback,
        bool $refresh = false,
        string|Expression|null $findBy = null,
        mixed $findValue = null,
        string $findOperator = '='
    ): ?Model {
        if (! $refresh && $this->redis->exists($this->hashKey())) {
            $result = $this->findInCache($findBy, $findValue, $findOperator);

            if ($result !== null) {
                return $result;
            }
        }

        $models = collect($callback());

        if ($models->isEmpty()) {
            return null;
        }

        $this->storeMany($models);

        return $this->findInCache($findBy, $findValue, $findOperator);
    }

    protected function findInCache(string|Expression|null $findBy, mixed $findValue, string $findOperator): ?Model
    {
        foreach ($this->redis->hgetall($this->hashKey()) as $item) {
            $data = $this->deserialize($item);
            $model = $this->newModelFromCache($data);

            if ($this->modelMatches($model, $findBy, $findValue, $findOperator)) {
                return $model;
            }
        }

        return null;
    }

    protected function modelMatches(Model $model, string|Expression|null $findBy, mixed $findValue, string $findOperator): bool
    {
        if ($findBy === null) {
            return false;
        }

        $fieldName = $findBy instanceof Expression
            ? $findBy->getValue($model->newQuery()->getGrammar())
            : $findBy;

        if ($findBy instanceof Expression) {
            preg_match_all('/(\w+)/', (string) $fieldName, $matches);
            $fields = array_intersect($matches[1], array_keys($model->getAttributes()));

            if ($fields !== []) {
                $concatenated = implode('|', array_map(fn (string $field): string => (string) ($model->{$field} ?? ''), $fields));

                return $this->matchStrategy->matches(
                    $this->matchStrategy->normalize($concatenated),
                    $findValue,
                    $findOperator
                );
            }
        }

        return $this->matchStrategy->matches($model->{$fieldName} ?? null, $findValue, $findOperator);
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
        return $this->customIndexKey($custom) . ":sorted:{$field}";
    }

    /**
     * @return array<int, string>
     */
    protected function collectKeysByPattern(string $pattern): array
    {
        $strategy = (string) config('redis-model-cache.scan_strategy', 'scan');
        $count = (int) config('redis-model-cache.scan_count', 1000);

        if ($strategy !== 'scan') {
            return $this->redis->keys($pattern);
        }

        if (is_a($this->redis, 'Predis\Client')) {
            $cursor = null;
            $keys = [];

            do {
                $chunk = $this->redis->scan($cursor, ['match' => $pattern, 'count' => $count]);

                if (is_array($chunk)) {
                    $keys = array_merge($keys, $chunk);
                }
            } while ($cursor !== 0 && $cursor !== '0' && $cursor !== null);

            return array_values(array_unique($keys));
        }

        if (method_exists($this->redis, 'scan')) {
            $iterator = null;
            $keys = [];

            do {
                $chunk = $this->redis->scan($iterator, $pattern, $count);

                if (is_array($chunk)) {
                    $keys = array_merge($keys, $chunk);
                }
            } while ($iterator !== 0 && $iterator !== '0' && $iterator !== null);

            return array_values(array_unique($keys));
        }

        return $this->redis->keys($pattern);
    }
}
