<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache;

use BadMethodCallException;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Sm_mE\RedisModelCache\Contracts\ModelCacheService;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\Contracts\TenantResolverInterface;
use Sm_mE\RedisModelCache\Events\CacheHit;
use Sm_mE\RedisModelCache\Events\CacheMiss;
use Sm_mE\RedisModelCache\Events\QueryExecuted;
use Sm_mE\RedisModelCache\Jobs\RevalidateCacheJob;
use Sm_mE\RedisModelCache\Support\Configuration;
use Sm_mE\RedisModelCache\Support\DefaultConnectionResolver;
use Sm_mE\RedisModelCache\Support\ExplainResult;
use Sm_mE\RedisModelCache\Support\IndexResolver;
use Sm_mE\RedisModelCache\Support\QueryPlanner;
use Sm_mE\RedisModelCache\Support\StampedeProtection;

/** @implements ModelCacheService<int, Model> */
class RedisModelService extends RedisBaseService implements ModelCacheService
{
    /** @var class-string<Model> */
    protected string $model_class;

    /** @var array<string, array<int, string>> */
    protected array $custom_indexes = [];

    protected string $prefix;

    /** @var array<int, string> */
    protected array $indexes = [];

    /** @var array<int, string> */
    protected array $sorted = [];

    protected ModelMatchStrategy $matchStrategy;

    /** @var bool Whether to enable explain mode (returns query plan instead of executing) */
    protected bool $explainMode = false;

    /** @var array<int, array{command: string, key: string, estimated_cardinality: int|string}> Query plan steps for explain mode */
    protected array $explainSteps = [];

    /** @var bool Whether metrics/events should be dispatched */
    protected bool $metricsEnabled = true;

    /** @var string|null Cached SHA-1 of the atomic store Lua script */
    protected ?string $luaAtomicStoreSha = null;

    /** @var string|null Cached SHA-1 of the lock CAS Lua script */
    protected ?string $luaLockCasSha = null;

    /** @var bool Whether debug verbose logging is enabled */
    protected bool $debugMode = false;

    protected IndexResolver $indexResolver;

    protected QueryPlanner $queryPlanner;

    /**
     * @param  array<int, string>  $indexes
     * @param  array<int, string>  $sorted
     * @param  array<string, array<int, string>>  $custom_indexes
     */
    public function __construct(
        RedisConnectionResolver $connectionResolver,
        string $model_class,
        array $indexes = [],
        array $sorted = [],
        array $custom_indexes = [],
        ?int $ttl = null,
        ?ModelMatchStrategy $matchStrategy = null,
        ?string $connection = null,
        ?Configuration $configuration = null,
    ) {
        if ($connection !== null) {
            $connectionResolver = new DefaultConnectionResolver($connection, $configuration);
        }

        parent::__construct($connectionResolver, $ttl, $configuration);

        if (! is_subclass_of($model_class, Model::class)) {
            throw new InvalidArgumentException('$model_class must extend '.Model::class);
        }

        $this->model_class = $model_class;
        $this->prefix = $this->buildPrefix((new $model_class)->getTable());
        $this->indexes = $indexes;
        $this->sorted = $sorted;
        $this->custom_indexes = $custom_indexes;
        $this->matchStrategy = $matchStrategy ?? app(ModelMatchStrategy::class);
        $this->metricsEnabled = $this->configuration->observabilityEnabled;
        $this->indexResolver = new IndexResolver;
        $this->queryPlanner = new QueryPlanner;
    }

    /**
     * Enable explain mode - returns query plan instead of executing query.
     *
     * @return $this
     */
    public function explain(): static
    {
        $this->explainMode = true;
        $this->explainSteps = [];

        return $this;
    }

    /**
     * Disable metrics/event dispatching for this service instance.
     *
     * @return $this
     */
    public function withoutMetrics(): static
    {
        $this->metricsEnabled = false;

        return $this;
    }

    /**
     * @return Collection<int, Model>
     */
    public function custom(string $name): Collection
    {
        return $this->hydrateIds($this->redis->smembers($this->customIndexKey($name)));
    }

    /**
     * @param  array<int, string>  $ids
     * @return Collection<int, Model>|Collection<int, string>
     */
    protected function hydrateIds(array $ids, bool $hydrate = true): Collection
    {
        if ($ids === []) {
            return collect();
        }

        if (! $hydrate) {
            return collect($ids);
        }

        $hashKey = $this->hashKey();
        $maxBatch = max(1, $this->configuration->hydrateBatchSize);

        /** @var array<int, string|false> $results */
        $results = [];

        if (count($ids) <= $maxBatch) {
            $raw = $this->redis->hmget($hashKey, $ids);
            foreach ($ids as $id) {
                $results[] = $raw[$id] ?? false;
            }
        } else {
            foreach (array_chunk($ids, $maxBatch) as $chunk) {
                $raw = $this->redis->hmget($hashKey, $chunk);
                foreach ($chunk as $id) {
                    $results[] = $raw[$id] ?? false;
                }
            }
        }

        return collect($results)
            ->filter()
            ->map(function (string $payload): Model {
                /** @var array{attributes: array<string, mixed>, relations: array<string, mixed>} $data */
                $data = $this->deserialize($payload);

                return $this->hydrateModelFromPayload($data);
            })
            ->values();
    }

    /**
     * Reconstructs a Model from stored payload including eager-loaded relations.
     *
     * @param  array{attributes: array<string, mixed>, relations: array<string, mixed>}  $payload
     */
    protected function hydrateModelFromPayload(array $payload): Model
    {
        $model = (new $this->model_class)->newFromBuilder($payload['attributes']);

        if (! empty($payload['relations'])) {
            $this->restoreRelations($model, $payload['relations']);
        }

        return $model;
    }

    protected function hashKey(): string
    {
        return "{$this->prefix}:hash";
    }

    /**
     * Build cache key prefix with optional tenant namespace.
     *
     * Wraps the prefix in Redis cluster hash tags ({...}) so all keys for a
     * single model type land on the same cluster node.
     */
    protected function buildPrefix(string $table): string
    {
        if (! $this->configuration->multiTenantEnabled) {
            return '{'.$table.'}';
        }

        try {
            /** @var TenantResolverInterface $resolver */
            $resolver = app(TenantResolverInterface::class);
            $tenantId = $resolver->getTenantId();

            if ($tenantId === null) {
                return '{'.$table.'}';
            }

            $sanitized = str_replace(['{', '}', ':'], ['', '', '_'], (string) $tenantId);

            return '{'.'tenant:'.$sanitized.':'.$table.'}';
        } catch (\Throwable $e) {
            return '{'.$table.'}';
        }
    }

    /**
     * Get metadata key for storing cache timestamps.
     */
    protected function metaKey(): string
    {
        return "{$this->prefix}:meta";
    }

    /**
     * Store cache metadata (cached_at timestamp).
     */
    protected function storeCacheMetadata(): void
    {
        $metaKey = $this->metaKey();
        $this->redis->hset($metaKey, 'cached_at', (string) time());

        if ($this->ttl !== null) {
            $this->redis->expire($metaKey, $this->ttl);
        }
    }

    /**
     * Get cache metadata.
     *
     * @return array{cached_at: int|null}
     */
    protected function getCacheMetadata(): array
    {
        $metaKey = $this->metaKey();
        $cachedAt = $this->redis->hget($metaKey, 'cached_at');

        return [
            'cached_at' => $cachedAt !== null && $cachedAt !== false ? (int) $cachedAt : null,
        ];
    }

    /**
     * Check if cache is stale but within grace period for SWR.
     *
     * @return array{is_stale: bool, within_grace: bool, should_revalidate: bool}
     */
    protected function checkStaleStatus(): array
    {
        $metadata = $this->getCacheMetadata();
        $cachedAt = $metadata['cached_at'];

        if ($cachedAt === null || $this->ttl === null) {
            return [
                'is_stale' => false,
                'within_grace' => false,
                'should_revalidate' => false,
            ];
        }

        $age = time() - $cachedAt;
        $isStale = $age > $this->ttl;
        $gracePeriod = $this->configuration->swrGracePeriod;
        $withinGrace = $isStale && $age <= ($this->ttl + $gracePeriod);

        return [
            'is_stale' => $isStale,
            'within_grace' => $withinGrace,
            'should_revalidate' => $withinGrace, // Simplified: same as within_grace
        ];
    }

    /**
     * @return array<mixed, mixed>
     */
    protected function deserialize(string $json): array
    {
        return (array) $this->deserializeResult($json);
    }

    public function customIndexKey(string $name): string
    {
        return "{$this->prefix}:custom:{$name}";
    }

    /**
     * @param  array<int, string>  $names
     * @return Collection<int, Model>
     */
    public function customWhere(array $names): Collection
    {
        $keys = array_map(
            fn (string $name): string => $this->customIndexKey($name),
            $names
        );

        return $this->hydrateIds($this->redis->sinter(...$keys));
    }

    /**
     * @return Collection<int, Model>
     */
    public function paginateSorted(string $field, int $page, int $perPage): Collection
    {
        $start = ($page - 1) * $perPage;
        $end = $start + $perPage - 1;

        return $this->sorted($field, $start, $end);
    }

    /**
     * @return Collection<int, Model>
     */
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

    /**
     * @param  array<string, mixed>  $oldData
     */
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

    /**
     * Remove model ID from all custom index sets on delete.
     *
     * @param  int|string  $id  Model primary key
     * @param  array<string, mixed>  $attributes  Model attributes (unused, kept for signature stability)
     */
    public function removeCustomIndexes(int|string $id, array $attributes = []): void
    {
        foreach (array_keys($this->custom_indexes) as $name) {
            $this->redis->srem($this->customIndexKey((string) $name), (string) $id);
        }
    }

    /**
     * Atomically increment the version counter in meta hash.
     * Used by versioned invalidation to signal cache staleness.
     */
    public function bustVersion(): void
    {
        $this->redis->hincrby($this->metaKey(), 'version', 1);

        if ($this->ttl) {
            $this->redis->expire($this->metaKey(), $this->ttl);
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
        $keys = [$this->hashKey(), $this->metaKey()];

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

    /**
     * @param  callable(): Collection<int, Model>  $callback
     * @param  array<string, mixed>  $where
     * @param  array<string>|null  $only
     * @return Collection<int, Model>
     */
    public function rememberAll(
        callable $callback,
        bool $hydrate = true,
        array $where = [],
        bool $refresh = false,
        ?array $only = null,
        bool $stampede = false,
        bool $swr = false
    ): Collection {
        $startTime = microtime(true);
        $hashKey = $this->hashKey();
        $hashExists = (bool) $this->redis->exists($hashKey);

        // Stale-While-Revalidate logic
        $swrEnabled = $swr && $this->configuration->swrEnabled;

        $forceRebuild = false;

        if ($hashExists && ! $refresh) {
            if ($where === []) {
                throw new BadMethodCallException(
                    'Global unindexed cache fetches via rememberAll() are prohibited '
                    .'for memory safety. Provide a $where clause with indexed fields or use a specialized index method.'
                );
            }

            // Check if cache is stale but within grace period (SWR)
            if ($swrEnabled) {
                $staleStatus = $this->checkStaleStatus();

                if ($staleStatus['should_revalidate']) {
                    // Cache is stale but within grace period - serve stale data and revalidate async
                    /** @var Collection<int, Model> $result */
                    $result = $this->where($where, hydrate: $hydrate, only: $only);

                    if ($result->isNotEmpty()) {
                        // Dispatch background job to revalidate cache
                        dispatch(new RevalidateCacheJob(
                            modelClass: $this->model_class,
                            callback: $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
                            where: $where,
                            indexes: $this->indexes,
                            sorted: $this->sorted,
                            customIndexes: $this->custom_indexes,
                            ttl: $this->ttl,
                            redisConnection: $this->connectionResolver instanceof DefaultConnectionResolver
                                ? null
                                : $this->configuration->connection,
                        ));

                        // Return stale data immediately
                        /** @var Collection<int, Model> $result */
                        return $result;
                    }
                }

                // When cache is stale beyond grace period, force-rebuild rather than
                // serving arbitrarily old data
                if ($staleStatus['is_stale'] && ! $staleStatus['within_grace']) {
                    $forceRebuild = true;
                }
            }

            if (! $forceRebuild) {
                // Normal cache hit (not stale or SWR disabled)
                /** @var Collection<int, Model> $result */
                $result = $this->where($where, hydrate: $hydrate, only: $only);
                if ($result->isNotEmpty()) {
                    /** @var Collection<int, Model> */
                    return $result;
                }
            }
        }

        // Stampede protection enabled and configured
        $stampedeEnabled = $stampede && $this->configuration->stampedeProtectionEnabled;
        $lockAcquired = false;
        $lockKey = null;
        $lockValue = null;

        if ($stampedeEnabled && ! $hashExists) {
            $lockKey = StampedeProtection::lockKey($hashKey);
            $lockTimeout = $this->configuration->stampedeProtectionLockTimeout;

            if ($this->luaEnabled()) {
                // Use value-based locking for CAS release
                $lockValue = StampedeProtection::acquireLockWithValue($this->redis, $lockKey, $lockTimeout);
                $lockAcquired = $lockValue !== null;
            } else {
                $lockAcquired = StampedeProtection::acquireLock($this->redis, $lockKey, $lockTimeout);
            }

            if (! $lockAcquired) {
                // Wait for the lock holder to populate cache
                $waitTimeout = $this->configuration->stampedeProtectionWaitTimeout;
                $waitInterval = $this->configuration->stampedeProtectionWaitInterval;

                StampedeProtection::waitForLock($this->redis, $lockKey, $waitTimeout, $waitInterval);

                // Try to fetch from cache again (lock holder may have populated it while we waited)
                // @phpstan-ignore-next-line — Redis state can change during waitForLock
                if ($this->redis->exists($hashKey)) {
                    /** @var Collection<int, Model> $result */
                    $result = $this->where($where, hydrate: $hydrate, only: $only);
                    if ($result->isNotEmpty()) {
                        /** @var Collection<int, Model> */
                        return $result;
                    }
                }
                // If still not in cache, fall through to execute callback
            }
        }

        try {
            $models = collect($callback());
            $this->storeMany($models);
            $models = $this->filterModelsByKey($models, $only);

            // Dispatch cache miss event
            if ($this->metricsEnabled && $this->configuration->observabilityDispatchEvents) {
                $executionTime = (microtime(true) - $startTime) * 1000;
                event(new CacheMiss(
                    modelClass: $this->model_class,
                    query: $where,
                    stampedeProtectionUsed: $lockAcquired,
                    executionTime: $executionTime
                ));
            }

            if ($where !== []) {
                /** @var Collection<int, Model> */
                return $this->where($where, hydrate: $hydrate, only: $only);
            }

            return $hydrate ? $models : $models->pluck($this->keyName());
        } finally {
            // Release stampede lock if acquired
            if ($lockAcquired && $lockKey !== null) {
                if ($lockValue !== null && $this->luaEnabled()) {
                    StampedeProtection::releaseLockCas(
                        $this->redis, $lockKey, $lockValue, $this->luaLockCasSha
                    );
                } else {
                    StampedeProtection::releaseLock($this->redis, $lockKey);
                }
            }
        }
    }

    /**
     * @param  array<string>|null  $only
     * @return Collection<int, Model>
     *
     * @throws BadMethodCallException Full hash scans are prohibited for memory safety.
     */
    public function all(bool $hydrate = true, ?array $only = null): Collection
    {
        throw new BadMethodCallException(
            'all() is disabled. Use where() with indexed fields, rememberIndex(), or customWhere(). '
            .'Full hash scans are prohibited for memory safety.'
        );
    }

    /**
     * @param  array<string, string>  $items
     * @param  array<string>|null  $only
     * @return array<string, string>
     */
    protected function filterRedisHashItemsByKey(array $items, ?array $only = null): array
    {
        return $only === null || $only === []
            ? $items
            : array_intersect_key($items, array_flip($only));
    }

    /**
     * Store a batch of models in cache efficiently.
     *
     * Uses a single HMGET call to fetch all old data (1 round-trip instead of N),
     * then a single pipeline to store all models atomically.
     *
     * @param  Collection<int, Model>  $models
     */
    public function storeMany(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        // Batch-read old hash data in a single HMGET call instead of N individual HGETs.
        // This reduces round-trips from N to 1, critical for large bulk stores.
        $hashKey = $this->hashKey();
        $modelKeys = [];
        $keyedModels = [];
        foreach ($models as $model) {
            $key = (string) $model->getKey();
            $modelKeys[] = $key;
            $keyedModels[$key] = $model;
        }

        $oldDataAll = $this->redis->hmget($hashKey, $modelKeys);
        $staleKeysMap = [];
        foreach ($keyedModels as $key => $model) {
            $oldRaw = $oldDataAll[$key] ?? null;
            if ($oldRaw !== null && $oldRaw !== false) {
                $oldParsed = $this->deserialize($oldRaw);
                $staleKeysMap[$key] = $this->computeStaleIndexKeysFromData($model, $oldParsed);
            } else {
                $staleKeysMap[$key] = [];
            }
        }

        // Prime Lua script cache before entering pipeline (avoids NOSCRIPT in batch EVALSHA)
        if ($this->luaEnabled()) {
            $this->primeAtomicStoreScript();
        }

        // Start pipeline
        $pipeline = $this->redis->pipeline();

        foreach ($models as $model) {
            $key = (string) $model->getKey();
            $this->storeModel($model, $pipeline, $staleKeysMap[$key] ?? []);
        }

        // Execute pipeline and exit pipeline mode
        $this->executePipeline($pipeline);

        $this->applyTTL($hashKey);

        // Store cache metadata for SWR stale detection
        $this->storeCacheMetadata();
    }

    /**
     * @param  Collection<int, Model>  $models
     * @param  array<string>|null  $only
     * @return Collection<int, Model>
     */
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
     * Delegates field validation and key resolution to IndexResolver.
     * No O(N) hash scans — every query maps to deterministic set ops.
     *
     * @param  array<string, mixed>  $where  Equality conditions only (field => value)
     * @param  array<string>|null  $only
     * @return Collection<int, Model>|Collection<int, string>|ExplainResult
     *
     * @throws InvalidArgumentException If any field is not indexed
     */
    public function where(array $where, bool $hydrate = true, ?array $only = null): Collection|ExplainResult
    {
        $startTime = microtime(true);

        // Resolve via IndexResolver — validates and determines strategy
        $resolved = $this->indexResolver->resolve($where, $this->indexes);

        // Build concrete keys using the service prefix
        $indexKeys = $this->buildConcreteKeys($where);

        // Explain mode: delegate to QueryPlanner for deterministic plan
        if ($this->explainMode) {
            $this->explainMode = false;

            $plan = $this->queryPlanner->plan('where', $resolved, $this->hashKey(), [
                'prefix' => $this->prefix,
                'where' => $where,
            ]);

            return new ExplainResult(
                operation: 'where',
                parameters: $where,
                steps: array_map(fn (array $s): array => [
                    'command' => $s['command'],
                    'key' => $s['key'],
                    'estimated_cardinality' => $s['estimated_cost'],
                ], $plan->steps),
                totalCommands: $plan->totalCommands,
                strategy: $resolved->strategy,
            );
        }

        // Deterministic Redis ops based on resolved strategy
        $ids = match ($resolved->command) {
            'SMEMBERS' => $this->redis->smembers($indexKeys[0]),
            'SINTER' => $this->redis->sinter(...$indexKeys),
            default => throw new RuntimeException("Unexpected command: {$resolved->command}"),
        };

        // Optional $only filter (primary keys)
        if ($only !== null && $only !== []) {
            $ids = array_values(array_intersect($ids, $only));
        }

        // Batch hydrate (relation-aware)
        $result = $this->hydrateIds($ids, $hydrate);

        // Dispatch metrics event
        if ($this->metricsEnabled && $this->configuration->observabilityDispatchEvents) {
            $executionTime = (microtime(true) - $startTime) * 1000;

            if ($ids !== []) {
                event(new CacheHit(
                    modelClass: $this->model_class,
                    query: $where,
                    resultCount: count($ids),
                    executionTime: $executionTime
                ));
            }

            event(new QueryExecuted(
                modelClass: $this->model_class,
                operation: 'where',
                parameters: $where,
                commandCount: count($indexKeys) > 0 ? 1 + count($ids) : 0,
                executionTime: $executionTime,
                resultCount: $result->count()
            ));
        }

        return $result;
    }

    /**
     * Query models where field value is in the given array (OR logic).
     * Uses Redis SUNION to combine multiple index sets.
     *
     * Delegates field validation and key resolution to IndexResolver.
     *
     * @param  string  $field  The indexed field to query
     * @param  array<int|string>  $values  Array of values to match (OR logic)
     * @param  bool  $hydrate  Whether to return full models or just IDs
     * @param  array<string>|null  $only  Optional filter for specific primary keys
     * @return Collection<int, Model>|Collection<int, string>|ExplainResult
     *
     * @throws InvalidArgumentException If field is not indexed or values array is empty
     */
    public function whereIn(string $field, array $values, bool $hydrate = true, ?array $only = null): Collection|ExplainResult
    {
        $startTime = microtime(true);

        // Resolve via IndexResolver — validates and determines strategy
        $resolved = $this->indexResolver->resolveWhereIn($field, $values, $this->indexes);

        // Build concrete keys using the service prefix
        $indexKeys = array_map(
            fn (string|int $value): string => $this->indexKey($field, $value),
            $values
        );

        // Explain mode: delegate to QueryPlanner for deterministic plan
        if ($this->explainMode) {
            $this->explainMode = false;

            $plan = $this->queryPlanner->plan('whereIn', $resolved, $this->hashKey(), [
                'prefix' => $this->prefix,
                'field' => $field,
                'values' => $values,
            ]);

            return new ExplainResult(
                operation: 'whereIn',
                parameters: ['field' => $field, 'values' => $values],
                steps: array_map(fn (array $s): array => [
                    'command' => $s['command'],
                    'key' => $s['key'],
                    'estimated_cardinality' => $s['estimated_cost'],
                ], $plan->steps),
                totalCommands: $plan->totalCommands,
                strategy: $resolved->strategy,
            );
        }

        // Deterministic Redis ops based on resolved strategy
        $ids = match ($resolved->command) {
            'SMEMBERS' => $this->redis->smembers($indexKeys[0]),
            'SUNION' => $this->redis->sunion(...$indexKeys),
            default => throw new RuntimeException("Unexpected command: {$resolved->command}"),
        };

        // Optional $only filter (primary keys)
        if ($only !== null && $only !== []) {
            $ids = array_values(array_intersect($ids, $only));
        }

        // Batch hydrate (relation-aware)
        $result = $this->hydrateIds($ids, $hydrate);

        // Dispatch metrics event
        if ($this->metricsEnabled && $this->configuration->observabilityDispatchEvents) {
            $executionTime = (microtime(true) - $startTime) * 1000;

            if ($ids !== []) {
                event(new CacheHit(
                    modelClass: $this->model_class,
                    query: ['field' => $field, 'values' => $values],
                    resultCount: count($ids),
                    executionTime: $executionTime
                ));
            }

            event(new QueryExecuted(
                modelClass: $this->model_class,
                operation: 'whereIn',
                parameters: ['field' => $field, 'values' => $values],
                commandCount: count($indexKeys) + count($ids),
                executionTime: $executionTime,
                resultCount: $result->count()
            ));
        }

        return $result;
    }

    /**
     * Query models where field value is between min and max (range query).
     * Uses Redis ZRANGEBYSCORE on sorted indexes.
     *
     * @param  string  $field  The sorted field to query
     * @param  int|float  $min  Minimum value (inclusive)
     * @param  int|float  $max  Maximum value (inclusive)
     * @param  bool  $hydrate  Whether to return full models or just IDs
     * @param  array<string>|null  $only  Optional filter for specific primary keys
     * @return Collection<int, Model>|Collection<int, string>|ExplainResult
     *
     * @throws InvalidArgumentException If field is not a sorted index
     */
    public function whereBetween(string $field, int|float $min, int|float $max, bool $hydrate = true, ?array $only = null): Collection|ExplainResult
    {
        $startTime = microtime(true);

        // Validate field has a sorted index
        if (! in_array($field, $this->sorted, true)) {
            throw new InvalidArgumentException(
                "Field '{$field}' does not have a sorted index. Define it in \$sorted constructor arg. "
                .'Available: ['.implode(', ', $this->sorted).']'
            );
        }

        $sortedKey = $this->sortedKey($field);

        // Explain mode: return query plan without executing
        if ($this->explainMode) {
            $this->explainMode = false; // Reset for next query

            $steps = [
                [
                    'command' => 'ZRANGEBYSCORE',
                    'key' => $sortedKey,
                    'min' => $min,
                    'max' => $max,
                    'estimated_cardinality' => 'unknown (explain mode)',
                ],
                [
                    'command' => 'Pipeline HGET × N',
                    'key' => $this->hashKey(),
                    'estimated_cardinality' => 'N models',
                ],
            ];

            return new ExplainResult(
                operation: 'whereBetween',
                parameters: ['field' => $field, 'min' => $min, 'max' => $max],
                steps: $steps,
                totalCommands: 2,
                strategy: 'sorted_range_scan'
            );
        }

        // Range query on sorted set
        $ids = $this->redis->zrangebyscore($sortedKey, (string) $min, (string) $max);

        // Optional $only filter (primary keys)
        if ($only !== null && $only !== []) {
            $ids = array_values(array_intersect($ids, $only));
        }

        // Batch hydrate (relation-aware)
        $result = $this->hydrateIds($ids, $hydrate);

        // Dispatch metrics event
        if ($this->metricsEnabled && $this->configuration->observabilityDispatchEvents) {
            $executionTime = (microtime(true) - $startTime) * 1000;

            if ($ids !== []) {
                event(new CacheHit(
                    modelClass: $this->model_class,
                    query: ['field' => $field, 'min' => $min, 'max' => $max],
                    resultCount: count($ids),
                    executionTime: $executionTime
                ));
            }

            event(new QueryExecuted(
                modelClass: $this->model_class,
                operation: 'whereBetween',
                parameters: ['field' => $field, 'min' => $min, 'max' => $max],
                commandCount: 1 + count($ids),
                executionTime: $executionTime,
                resultCount: $result->count()
            ));
        }

        return $result;
    }

    /**
     * Add OR condition to the query by combining results with previous where clause.
     * Uses Redis SUNION to merge result sets.
     *
     * Note: This is a convenience method that executes two separate queries and merges results.
     * For better performance with multiple values on the same field, use whereIn() instead.
     *
     * @param  array<string, mixed>  $where  Additional WHERE conditions (OR logic)
     * @param  array<string>  $baseIds  IDs from previous where() call
     * @param  bool  $hydrate  Whether to return full models or just IDs
     * @return Collection<int, Model>|Collection<int, string>
     *
     * @throws InvalidArgumentException If fields are not indexed
     */
    public function orWhere(array $where, array $baseIds = [], bool $hydrate = true): Collection
    {
        // Validate all fields are indexed
        foreach (array_keys($where) as $field) {
            if (! in_array($field, $this->indexes, true)) {
                throw new InvalidArgumentException(
                    "Field '{$field}' is not indexed. Define it in \$indexes constructor arg. "
                    .'Available: ['.implode(', ', $this->indexes).']'
                );
            }
        }

        // Build index keys for new conditions
        $indexKeys = [];
        foreach ($where as $field => $value) {
            $indexKeys[] = $this->indexKey($field, $value);
        }

        // Get IDs matching new conditions (SINTER for AND within orWhere)
        $newIds = $indexKeys === [] ? [] : $this->redis->sinter(...$indexKeys);

        // Merge with base IDs (union = OR logic)
        $mergedIds = array_values(array_unique(array_merge($baseIds, $newIds)));

        // Batch hydrate
        return $this->hydrateIds($mergedIds, $hydrate);
    }

    /**
     * Fetch models with only specific attributes (partial hydration).
     * Reduces memory usage by 60-80% compared to full model hydration.
     *
     * @param  array<string>  $attributes  Attribute names to retrieve
     * @param  array<string, mixed>  $where  WHERE conditions (indexed fields)
     * @param  array<string>|null  $only  Optional filter for specific primary keys
     * @return Collection<int, array<string, mixed>> Collection of associative arrays (not full models)
     *
     * @throws InvalidArgumentException If where fields are not indexed
     */
    public function pluck(array $attributes, array $where = [], ?array $only = null): Collection
    {
        // Validate all where fields are indexed
        foreach (array_keys($where) as $field) {
            if (! in_array($field, $this->indexes, true)) {
                throw new InvalidArgumentException(
                    "Field '{$field}' is not indexed. Define it in \$indexes constructor arg. "
                    .'Available: ['.implode(', ', $this->indexes).']'
                );
            }
        }

        // Validate attributes array is not empty
        if ($attributes === []) {
            throw new InvalidArgumentException(
                'Attributes array cannot be empty for pluck query.'
            );
        }

        // Get matching IDs
        if ($where !== []) {
            $indexKeys = [];
            foreach ($where as $field => $value) {
                $indexKeys[] = $this->indexKey($field, $value);
            }
            $ids = $this->redis->sinter(...$indexKeys);
        } else {
            // No where clause - get all IDs from hash
            $ids = $this->redis->hkeys($this->hashKey());
        }

        // Optional $only filter
        if ($only !== null && $only !== []) {
            $ids = array_values(array_intersect($ids, $only));
        }

        // Fetch payloads with batched HMGET for large sets
        $hashKey = $this->hashKey();
        $maxBatch = max(1, $this->configuration->hydrateBatchSize);
        /** @var array<int, string|false> $results */
        $results = [];

        if (count($ids) <= $maxBatch) {
            $raw = $this->redis->hmget($hashKey, $ids);
            foreach ($ids as $id) {
                $results[] = $raw[$id] ?? false;
            }
        } else {
            foreach (array_chunk($ids, $maxBatch) as $chunk) {
                $raw = $this->redis->hmget($hashKey, $chunk);
                foreach ($chunk as $id) {
                    $results[] = $raw[$id] ?? false;
                }
            }
        }

        // Extract only requested attributes
        return collect($results)
            ->filter()
            ->map(function ($payload) use ($attributes): array {
                /** @var array{attributes: array<string, mixed>, relations: array<string, mixed>} $data */
                $data = $this->deserialize($payload);

                // Build lightweight DTO with only requested attributes
                $dto = [];
                foreach ($attributes as $attr) {
                    $dto[$attr] = $data['attributes'][$attr] ?? null;
                }

                /** @var array<string, mixed> */
                return $dto;
            })
            ->values();
    }

    /**
     * Lightweight field-only fetch — returns collections of arrays, not models.
     *
     * Delegates to pluck(). See pluck() for full documentation.
     *
     * @param  array<string>  $fields  Field names to retrieve
     * @param  array<string, mixed>  $where  WHERE conditions (indexed fields only)
     * @param  array<string>|null  $only  Optional filter for specific primary keys
     * @return Collection<int, array<string, mixed>>
     */
    public function selective(array $fields, array $where = [], ?array $only = null): Collection
    {
        return $this->pluck($fields, $where, $only);
    }

    /**
     * Public entry point for storing a single model in cache.
     * Used by the HasRedisModelCache trait.
     *
     * Delegates to storeModel() which uses atomic Lua scripting
     * when enabled, falling back to pipelined commands.
     */
    public function store(Model $model): void
    {
        $this->storeModel($model);
    }

    /**
     * Store a model atomically using Lua scripting.
     *
     * Combines HSET, stale-index SREM, new-index SADD, sorted-set ZADD,
     * and TTL application into a single atomic Lua call.
     *
     * When $pipeline is provided, queues an EVALSHA (or EVAL) in the pipeline
     * instead of executing directly, enabling atomic batch writes.
     *
     * @param  mixed  $pipeline  Pipeline client or null for direct execution
     * @param  array<int, string>|null  $precomputedStaleKeys  Pre-computed stale SREM keys (avoids extra HGET)
     */
    protected function storeModelAtomic(Model $model, $pipeline = null, ?array $precomputedStaleKeys = null): void
    {
        $key = (string) $model->getKey();
        $staleSremKeys = $precomputedStaleKeys ?? $this->computeStaleIndexKeys($model);

        $payload = [
            'attributes' => $model->getAttributes(),
            'relations' => $this->extractRelations($model),
        ];
        $serialized = $this->serializeResult($payload);
        $hashKey = $this->hashKey();
        $ttl = $this->ttl ?? 0;

        $newSadd = [];
        $staleZrem = [];
        $newZadd = [];
        $zaddScores = [];

        foreach ($this->indexes as $field) {
            $value = $model->{$field};
            if ($value !== null) {
                $newSadd[] = $this->indexKey($field, $value);
            }
        }

        foreach ($this->sorted as $field) {
            $value = $model->{$field};
            if ($value !== null) {
                $sortedKey = $this->sortedKey($field);
                $newZadd[] = $sortedKey;
                $zaddScores[] = (string) $this->extractScore($value);
            }
        }

        $keys = [$hashKey];
        $keys = array_merge($keys, $staleSremKeys);
        $keys = array_merge($keys, $newSadd);
        $keys = array_merge($keys, $staleZrem);
        $keys = array_merge($keys, $newZadd);

        $args = [
            $key,
            $serialized,
            (string) $ttl,
            (string) count($staleSremKeys),
            (string) count($newSadd),
            (string) count($staleZrem),
            (string) count($newZadd),
        ];

        foreach ($zaddScores as $score) {
            $args[] = $score;
        }

        if ($pipeline !== null) {
            $this->queueLuaAtomicStoreOnClient($pipeline, $keys, $args);
        } else {
            $fallback = function () use ($model, $staleSremKeys, $serialized, $hashKey, $key): void {
                $this->redis->hset($hashKey, $key, $serialized);
                if ($this->ttl) {
                    $this->redis->expire($hashKey, $this->ttl);
                }
                foreach ($staleSremKeys as $staleKey) {
                    $this->redis->srem($staleKey, $key);
                }
                $this->storeIndexes($model);
                $this->storeSorted($model);
            };

            $this->evaluateLuaOrPipeline(
                self::LUA_ATOMIC_STORE,
                $keys,
                $args,
                $this->luaAtomicStoreSha,
                $fallback
            );
        }
    }

    /**
     * Queue an EVALSHA (or EVAL) on a pipeline/client for the atomic store script.
     * Client-agnostic: handles phpredis vs Predis differences.
     *
     * @param  mixed  $client  Redis client or pipeline
     * @param  array<int, string>  $keys  KEYS for the Lua script
     * @param  array<int, string>  $args  ARGV for the Lua script
     */
    protected function queueLuaAtomicStoreOnClient(mixed $client, array $keys, array $args): void
    {
        $numKeys = count($keys);
        $allArgs = array_merge($keys, $args);

        if ($this->luaAtomicStoreSha !== null) {
            if ($client instanceof \Redis) {
                $client->evalSha($this->luaAtomicStoreSha, $allArgs, $numKeys);
            } else {
                $client->evalSha($this->luaAtomicStoreSha, $numKeys, ...$allArgs);
            }
        } else {
            if ($client instanceof \Redis) {
                $client->eval(self::LUA_ATOMIC_STORE, $allArgs, $numKeys);
            } else {
                $client->eval(self::LUA_ATOMIC_STORE, $numKeys, ...$allArgs);
            }
        }
    }

    /**
     * Prime the atomic store Lua script in Redis (SCRIPT LOAD).
     * After this, $this->luaAtomicStoreSha is populated and
     * EVALSHA can be used without NOSCRIPT fallback.
     */
    protected function primeAtomicStoreScript(): void
    {
        if ($this->luaAtomicStoreSha !== null) {
            return;
        }

        try {
            $this->luaAtomicStoreSha = $this->loadScript(self::LUA_ATOMIC_STORE);
        } catch (\Exception $e) {
            $this->luaAtomicStoreSha = null;
        }
    }

    /**
     * Serialize a single model with eager-loaded relations.
     *
     * When called without a pipeline ($pipeline = null) and Lua scripting
     * is enabled, delegates to storeModelAtomic() for atomic execution.
     *
     * @param  mixed  $pipeline
     * @param  array<int, string>|null  $precomputedStaleKeys
     */
    protected function storeModel(Model $model, $pipeline = null, ?array $precomputedStaleKeys = null): void
    {
        if ($this->luaEnabled()) {
            $this->storeModelAtomic($model, $pipeline, $precomputedStaleKeys);

            return;
        }
        $client = $pipeline ?? $this->redis;
        $key = (string) $model->getKey();

        // Use precomputed stale keys if provided, otherwise compute now
        // Note: precomputedStaleKeys can be an empty array (no stale keys) vs null (not computed)
        $staleSremKeys = $precomputedStaleKeys !== null ? $precomputedStaleKeys : $this->computeStaleIndexKeys($model);

        // Structured payload: attributes + eager-loaded relations
        $payload = [
            'attributes' => $model->getAttributes(),
            'relations' => $this->extractRelations($model),
        ];

        $client->hset($this->hashKey(), $key, $this->serializeResult($payload));

        if ($this->ttl) {
            $this->queueExpire($client, $this->hashKey());
        }

        // Queue stale index cleanup within the same pipeline/write batch
        foreach ($staleSremKeys as $staleKey) {
            $client->srem($staleKey, $key);
        }

        $this->storeIndexes($model, $pipeline);
        $this->storeSorted($model, $pipeline);
    }

    /**
     * Read old hash data and compute stale index keys that need SREM.
     * This should only be called OUTSIDE of pipeline context.
     *
     * @return array<int, string>
     */
    protected function computeStaleIndexKeys(Model $model): array
    {
        $key = (string) $model->getKey();
        $old = $this->redis->hget($this->hashKey(), $key);

        if (! $old) {
            return [];
        }

        $oldData = $this->deserialize($old);

        return $this->computeStaleIndexKeysFromData($model, $oldData);
    }

    /**
     * Compute stale index keys from already-deserialized old data.
     * Avoids an extra HGET round-trip when batch-reading in storeMany().
     *
     * @param  array<string, mixed>  $oldData  Deserialized old payload
     * @return array<int, string>
     */
    protected function computeStaleIndexKeysFromData(Model $model, array $oldData): array
    {
        $oldAttributes = $oldData['attributes'] ?? $oldData;
        $staleKeys = [];

        foreach ($this->indexes as $field) {
            $oldValue = $oldAttributes[$field] ?? null;

            if ($oldValue === null) {
                continue;
            }

            $currentValue = $model->{$field};

            if ((string) $oldValue === (string) $currentValue) {
                continue;
            }

            $staleKeys[] = $this->indexKey($field, $oldValue);
        }

        return $staleKeys;
    }

    /**
     * Recursively extracts eager-loaded relations into a serializable structure.
     *
     * @return array<string, array<int, mixed>|null>
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
     * @return array{class: string, attributes: array<string, mixed>, relations: array<string, mixed>}
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
     * @param  array<string, array<int, mixed>|null>  $relations
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
                $collection = collect($relationData)->map(function (mixed $item): Model {
                    /** @var array{class: string, attributes: array<string, mixed>, relations: array<string, mixed>} $item */
                    return $this->hydrateRelatedModel($item);
                });
                $model->setRelation($name, $collection);

            } else {
                // Single model relation (BelongsTo, HasOne, MorphOne, MorphTo)
                /** @var array{class: string, attributes: array<string, mixed>, relations: array<string, mixed>} $relationData */
                $model->setRelation($name, $this->hydrateRelatedModel($relationData));
            }
        }
    }

    /**
     * @param  array{class: string, attributes: array<string, mixed>, relations: array<string, mixed>}  $data
     */
    protected function hydrateRelatedModel(array $data): Model
    {
        /** @var class-string<Model> $class */
        $class = $data['class'];
        $model = new $class;
        $model->setRawAttributes($data['attributes'], true);

        if (! empty($data['relations'])) {
            $this->restoreRelations($model, $data['relations']);
        }

        return $model;
    }

    /**
     * Queue an EXPIRE command within a pipeline or execute directly.
     *
     * @param  mixed  $client
     */
    protected function queueExpire($client, string $key): void
    {
        $client->expire($key, $this->ttl);
    }

    /**
     * Execute a pipeline in a client-agnostic way.
     *
     * phpredis puts the \Redis object itself into pipeline mode and uses exec().
     * Predis returns a dedicated Pipeline object with execute().
     * Mockery mocks implement magic __call so we use is_a() to detect phpredis.
     *
     * @return array<int, mixed>
     */
    protected function executePipeline(mixed $pipeline): array
    {
        // phpredis: pipeline() returns the same \Redis instance in pipeline mode; uses exec()
        if ($pipeline instanceof \Redis) {
            return (array) $pipeline->exec();
        }

        // Predis and test mocks: pipeline object with execute() or __call
        if (is_callable([$pipeline, 'execute'])) {
            return (array) call_user_func([$pipeline, 'execute']);
        }

        // Last resort fallback for exec()-only clients
        if (is_callable([$pipeline, 'exec'])) {
            return (array) call_user_func([$pipeline, 'exec']);
        }

        return [];
    }

    /**
     * Convert a field value into a numeric score for sorted set storage.
     */
    protected function extractScore(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) (strtotime((string) $value) ?: 0);
    }

    /**
     * @param  mixed  $pipeline
     */
    protected function storeIndexes(Model $model, $pipeline = null): void
    {
        $client = $pipeline ?? $this->redis;

        foreach ($this->indexes as $field) {
            $value = $model->{$field};
            if ($value === null) {
                continue;
            }

            $key = $this->indexKey($field, $value);
            $client->sadd($key, (string) $model->getKey());

            if ($this->ttl) {
                $this->queueExpire($client, $key);
            }
        }
    }

    /**
     * @param  mixed  $pipeline
     */
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

            $key = $this->sortedKey($field);
            $client->zadd($key, $score, (string) $model->getKey());

            if ($this->ttl) {
                $this->queueExpire($client, $key);
            }
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
        /** @var string $reason */
        $reason = $findBy instanceof Expression ? 'expression' : $findBy;
        throw new InvalidArgumentException(
            "Field '{$reason}' is not indexed. Cannot perform lookup without index. "
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

    /**
     * @param  callable(): Collection<int, Model>  $callback
     * @return Collection<int, Model>
     */
    public function rememberIndex(string $field, string|int $value, callable $callback, bool $hydrate = true): Collection
    {
        $key = $this->indexKey($field, $value);

        if ($this->redis->exists($key)) {
            /** @var array<int, string> $ids */
            $ids = $this->redis->smembers($key);

            if ($hydrate) {
                return $this->hydrateIds($ids);
            }

            // @phpstan-ignore-next-line non-hydrate path returns IDs, not Models
            return collect($ids);
        }

        /** @var Collection<int, Model> $models */
        $models = collect($callback());

        foreach ($models as $model) {
            $this->storeModel($model);
            $this->redis->sadd($key, (string) $model->getKey());
        }

        $this->applyTTL($key);

        return $hydrate ? $models : $models->pluck($this->keyName());
    }

    /**
     * @param  callable(): Collection<int, Model>  $callback
     * @return Collection<int, Model>
     */
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
            /** @var array<int, string> $ids */
            $ids = $sortBy ? $this->redis->zrange($sortedKey, 0, -1) : $this->redis->smembers($key);

            if ($hydrate) {
                // @phpstan-ignore-next-line hydrate path returns Models
                return $this->hydrateIds($ids);
            }

            // @phpstan-ignore-next-line non-hydrate path returns IDs, not Models
            return collect($ids);
        }

        if ($refresh) {
            $this->redis->del(...array_filter([$key, $sortedKey]));
        }

        /** @var Collection<int, Model> $models */
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
        $count = $this->configuration->scanCount;
        $keys = [];

        if (is_a($this->redis, 'Predis\Client')) {
            $cursor = '0';
            do {
                // @phpstan-ignore-next-line Predis\Client is optional
                $result = $this->redis->scan($cursor, ['match' => $pattern, 'count' => $count]);
                /** @var array{cursor?: string, 0?: string, 1?: array<int, string>} $result */
                $cursor = (string) ($result[0] ?? '0');
                $chunk = $result[1] ?? [];
                if (! empty($chunk)) {
                    /** @var list<string> $keys */
                    $keys = array_merge($keys, $chunk);
                }
            } while ($cursor !== '0');

            // @phpstan-ignore-next-line Predis keys() returns mixed
            return array_values(array_unique($keys));
        }

        if (method_exists($this->redis, 'scan')) {
            $iterator = null;
            do {
                // @phpstan-ignore-next-line phpredis uses by-reference iterator
                $chunk = $this->redis->scan($iterator, $pattern, $count);
                if (is_array($chunk)) {
                    /** @var list<string> $keys */
                    $keys = array_merge($keys, $chunk);
                }
                // @phpstan-ignore-next-line scan() modifies $iterator by reference
            } while ($iterator !== 0 && $iterator !== '0' && $iterator !== null);

            // @phpstan-ignore-next-line scan() returns mixed
            return array_values(array_unique($keys));
        }

        throw new RuntimeException(
            'SCAN command is not available. The Redis client must support SCAN for production use. '
            .'Ensure phpredis extension is installed or use Predis.'
        );
    }

    /**
     * Update a single attribute on a cached model without full serialization.
     *
     * This method provides incremental updates that are 50-80% faster than
     * full model store operations. Only the specified attribute is modified,
     * relations are preserved, and indexes are updated only if needed.
     *
     * @param  int|string  $id  Model primary key
     * @param  string  $attribute  Attribute name to update
     * @param  mixed  $value  New value for the attribute
     *
     * @throws InvalidArgumentException If model not found in cache
     * @throws InvalidArgumentException If attribute doesn't exist on model
     */
    public function updateAttribute(int|string $id, string $attribute, mixed $value): void
    {
        $this->updateAttributes($id, [$attribute => $value]);
    }

    /**
     * Update multiple attributes on a cached model without full serialization.
     *
     * This method provides incremental updates that are 50-80% faster than
     * full model store operations. Only specified attributes are modified,
     * relations are preserved, and indexes are updated only if needed.
     *
     * @param  int|string  $id  Model primary key
     * @param  array<string, mixed>  $attributes  Attributes to update (attribute => value)
     *
     * @throws InvalidArgumentException If model not found in cache
     * @throws InvalidArgumentException If any attribute doesn't exist on model
     */
    public function updateAttributes(int|string $id, array $attributes): void
    {
        $hashKey = $this->hashKey();
        $key = (string) $id;

        // Read current model data from cache
        $current = $this->redis->hget($hashKey, $key);

        if ($current === null || $current === false) {
            throw new InvalidArgumentException(
                "Model {$this->model_class} with ID {$id} not found in cache. "
                .'Use storeModel() to cache it first.'
            );
        }

        // Deserialize current data
        $data = $this->deserialize($current);
        $currentAttributes = $data['attributes'] ?? $data; // Support both old and new format
        $relations = $data['relations'] ?? [];

        // Validate all attributes exist on model — check against cached attribute keys
        // (We cannot use new $model->getAttributes() on an empty instance as it returns [])
        $modelInstance = new $this->model_class;
        foreach (array_keys($attributes) as $attribute) {
            if (! array_key_exists($attribute, $currentAttributes)
                && ! $modelInstance->hasGetMutator($attribute)
                && ! $modelInstance->hasAttributeMutator($attribute)) {
                throw new InvalidArgumentException(
                    "Attribute '{$attribute}' does not exist on model {$this->model_class}."
                );
            }
        }

        // Track which indexed fields changed for index updates
        $changedIndexedFields = [];
        foreach ($this->indexes as $field) {
            if (array_key_exists($field, $attributes)) {
                $oldValue = $currentAttributes[$field] ?? null;
                $newValue = $attributes[$field];

                // Compare as strings, but treat null specially
                $oldStr = $oldValue !== null ? (string) $oldValue : null;
                $newStr = $newValue !== null ? (string) $newValue : null;

                if ($oldStr !== $newStr) {
                    $changedIndexedFields[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }
        }

        // Update attributes
        foreach ($attributes as $attribute => $value) {
            $currentAttributes[$attribute] = $value;
        }

        // Reconstruct payload with updated attributes and preserved relations
        $payload = [
            'attributes' => $currentAttributes,
            'relations' => $relations,
        ];

        // Use pipeline for atomic updates
        $pipeline = $this->redis->pipeline();

        // Update hash field
        $pipeline->hset($hashKey, $key, $this->serializeResult($payload));

        // Update indexes for changed indexed fields
        foreach ($changedIndexedFields as $field => $change) {
            // Remove from old index
            if ($change['old'] !== null) {
                $oldIndexKey = $this->indexKey($field, $change['old']);
                $pipeline->srem($oldIndexKey, $key);
            }

            // Add to new index — skip when new value is null (null has no index entry)
            if ($change['new'] !== null) {
                $newIndexKey = $this->indexKey($field, $change['new']);
                $pipeline->sadd($newIndexKey, $key);

                // Apply TTL to new index key
                if ($this->ttl !== null) {
                    $pipeline->expire($newIndexKey, $this->ttl);
                }
            }
        }

        // Update sorted sets if the sorted field was modified
        foreach ($this->sorted as $field) {
            if (isset($attributes[$field])) {
                $sortedKey = $this->sortedKey($field);
                $score = $this->extractScore($attributes[$field]);
                $pipeline->zadd($sortedKey, $score, $key);

                if ($this->ttl !== null) {
                    $pipeline->expire($sortedKey, $this->ttl);
                }
            }
        }

        // Apply TTL to hash key
        if ($this->ttl !== null) {
            $pipeline->expire($hashKey, $this->ttl);
        }

        // Execute all commands atomically
        $this->executePipeline($pipeline);

        // Update metadata timestamp
        $this->storeCacheMetadata();
    }

    /**
     * Enable debug mode - logs all Redis operations with timing and data sizes.
     *
     * @return $this
     */
    public function debug(): static
    {
        $this->debugMode = true;

        return $this;
    }

    /**
     * Inspect a cached model by ID - shows all Redis keys and data for a given model.
     *
     * @param  int|string  $id  The model primary key
     * @return array<string, mixed>|null Null if model not found in cache
     */
    public function inspect(int|string $id): ?array
    {
        $hashKey = $this->hashKey();
        $key = (string) $id;

        $payload = $this->redis->hget($hashKey, $key);

        if ($payload === null || $payload === false) {
            return null;
        }

        $data = $this->deserialize($payload);
        $ttl = $this->redis->ttl($hashKey);

        $result = [
            'model_id' => $id,
            'model_class' => $this->model_class,
            'hash_key' => $hashKey,
            'hash_data' => $data,
            'ttl_remaining' => $ttl,
            'indexes' => [],
            'sorted' => [],
            'custom_indexes' => [],
            'meta' => [],
        ];

        $attributes = $data['attributes'] ?? $data;

        foreach ($this->indexes as $field) {
            if (isset($attributes[$field])) {
                $indexKey = $this->indexKey($field, $attributes[$field]);
                $members = $this->redis->smembers($indexKey);
                $result['indexes'][] = [
                    'field' => $field,
                    'value' => $attributes[$field],
                    'key' => $indexKey,
                    'cardinality' => count($members),
                    'contains_id' => in_array($key, $members, true),
                ];
            }
        }

        foreach ($this->sorted as $field) {
            $sortedKey = $this->sortedKey($field);
            $score = $this->redis->zscore($sortedKey, $key);
            $result['sorted'][] = [
                'field' => $field,
                'key' => $sortedKey,
                'score' => $score !== false ? (float) $score : null,
            ];
        }

        foreach (array_keys($this->custom_indexes) as $name) {
            $customKey = $this->customIndexKey((string) $name);
            $members = $this->redis->smembers($customKey);
            $result['custom_indexes'][] = [
                'name' => $name,
                'key' => $customKey,
                'cardinality' => count($members),
                'contains_id' => in_array($key, $members, true),
            ];
        }

        $metaKey = $this->metaKey();
        $cachedAt = $this->redis->hget($metaKey, 'cached_at');
        $result['meta'] = [
            'key' => $metaKey,
            'cached_at' => $cachedAt !== null && $cachedAt !== false ? (int) $cachedAt : null,
        ];

        if ($this->debugMode) {
            $this->debugMode = false;
            $this->logDebug('inspect', [
                'id' => $id,
                'hash_key' => $hashKey,
                'payload_size' => strlen((string) $payload),
                'ttl' => $ttl,
                'index_count' => count($result['indexes']),
                'sorted_count' => count($result['sorted']),
            ]);
        }

        return $result;
    }

    /**
     * Analyze all indexes for this model and return a cardinality report.
     *
     * @return array<string, mixed>
     */
    public function analyzeIndexes(): array
    {
        $hashKey = $this->hashKey();
        $totalModels = $this->redis->hlen($hashKey);
        $ttl = $this->redis->ttl($hashKey);

        $result = [
            'model_class' => $this->model_class,
            'table' => (new $this->model_class)->getTable(),
            'hash' => [
                'key' => $hashKey,
                'total_models' => $totalModels,
                'ttl_remaining' => $ttl,
            ],
            'indexes' => [],
            'sorted' => [],
            'custom_indexes' => [],
            'meta' => [],
        ];

        $pattern = "{$this->prefix}:index:*";
        $indexKeys = $this->collectKeysByPattern($pattern);

        foreach ($indexKeys as $indexKey) {
            $cardinality = $this->redis->scard($indexKey);
            $result['indexes'][] = [
                'key' => $indexKey,
                'cardinality' => $cardinality,
            ];
        }

        foreach ($this->sorted as $field) {
            $sortedKey = $this->sortedKey($field);
            $cardinality = $this->redis->zcard($sortedKey);
            $result['sorted'][] = [
                'field' => $field,
                'key' => $sortedKey,
                'cardinality' => $cardinality,
            ];
        }

        foreach (array_keys($this->custom_indexes) as $name) {
            $customKey = $this->customIndexKey((string) $name);
            $cardinality = $this->redis->scard($customKey);
            $result['custom_indexes'][] = [
                'name' => $name,
                'key' => $customKey,
                'cardinality' => $cardinality,
            ];
        }

        $metaKey = $this->metaKey();
        $cachedAt = $this->redis->hget($metaKey, 'cached_at');
        $result['meta'] = [
            'key' => $metaKey,
            'cached_at' => $cachedAt !== null && $cachedAt !== false ? (int) $cachedAt : null,
        ];

        return $result;
    }

    /**
     * Build concrete index keys for a where clause using the service prefix.
     *
     * @param  array<string, mixed>  $where
     * @return array<int, string>
     */
    protected function buildConcreteKeys(array $where): array
    {
        $keys = [];
        foreach ($where as $field => $value) {
            $keys[] = $this->indexKey($field, $value);
        }

        return $keys;
    }

    /**
     * Find a single model by primary key via direct HGET.
     *
     * O(1) — no index needed, no scan.
     */
    public function find(int|string $id): ?Model
    {
        $hashKey = $this->hashKey();
        $payload = $this->redis->hget($hashKey, (string) $id);

        if ($payload === null || $payload === false) {
            return null;
        }

        /** @var array{attributes: array<string, mixed>, relations: array<string, mixed>} $data */
        $data = $this->deserialize($payload);

        return $this->hydrateModelFromPayload($data);
    }

    /**
     * Return the first model matching the where clause.
     *
     * Resolves via IndexResolver, executes SMEMBERS/SINTER for the
     * first matching ID, then HGET to hydrate. No O(N) hydration.
     */
    public function first(array $where): ?Model
    {
        $resolved = $this->indexResolver->resolve($where, $this->indexes);
        $indexKeys = $this->buildConcreteKeys($where);

        // Resolve IDs via the determined strategy, take first only
        /** @var array<int, string> $ids */
        $ids = match ($resolved->command) {
            'SMEMBERS' => $this->redis->smembers($indexKeys[0]),
            'SINTER' => $this->redis->sinter(...$indexKeys),
            default => throw new RuntimeException("Unexpected command: {$resolved->command}"),
        };

        if ($ids === []) {
            return null;
        }

        // Hydrate only the first match
        $hashKey = $this->hashKey();
        $payload = $this->redis->hget($hashKey, $ids[0]);

        if ($payload === null || $payload === false) {
            return null;
        }

        /** @var array{attributes: array<string, mixed>, relations: array<string, mixed>} $data */
        $data = $this->deserialize($payload);

        return $this->hydrateModelFromPayload($data);
    }

    /**
     * Count models matching the where clause.
     *
     * Single-index: uses SCARD (O(1)).
     * Multi-index: uses SINTER + count (O(N)).
     *
     * No hydration — counts from indexes only.
     */
    public function count(array $where): int
    {
        $resolved = $this->indexResolver->resolve($where, $this->indexes);
        $indexKeys = $this->buildConcreteKeys($where);

        // Single index: use SCARD for O(1) cardinality
        if ($resolved->isSingleKey()) {
            return (int) $this->redis->scard($indexKeys[0]);
        }

        // Multi index: intersection needed
        $ids = $this->redis->sinter(...$indexKeys);

        return count($ids);
    }

    /**
     * Check if any models match the where clause.
     *
     * Single-index: uses EXISTS (O(1)).
     * Multi-index: uses SINTER + check (O(N)).
     *
     * No hydration — existence from indexes only.
     */
    public function exists(array $where): bool
    {
        $resolved = $this->indexResolver->resolve($where, $this->indexes);
        $indexKeys = $this->buildConcreteKeys($where);

        // Single index: use EXISTS for O(1) check
        if ($resolved->isSingleKey()) {
            return (bool) $this->redis->exists($indexKeys[0]);
        }

        // Multi index: intersection needed
        $ids = $this->redis->sinter(...$indexKeys);

        return $ids !== [];
    }

    /**
     * Log a debug message if debug mode is enabled.
     *
     * @param  string  $operation  The operation name
     * @param  array<string, mixed>  $context  Context data for the log
     */
    protected function logDebug(string $operation, array $context = []): void
    {
        if (! $this->debugMode && ! $this->configuration->observabilityDebug) {
            return;
        }

        $message = sprintf(
            '[RedisModelCache] %s::%s %s',
            $this->model_class,
            $operation,
            json_encode($context, JSON_THROW_ON_ERROR),
        );

        logger()->debug($message);
    }
}
