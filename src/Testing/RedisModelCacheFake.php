<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Testing;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use SMDev\RedisModelCache\Contracts\ModelCacheService;
use SMDev\RedisModelCache\RedisModelService;
use SMDev\RedisModelCache\Support\ExplainResult;

/**
 * In-memory stand-in for RedisModelService, suitable for unit testing.
 *
 * The fake extends RedisModelService because the model trait resolves that
 * concrete type. Its constructor intentionally does not initialize Redis
 * state; all supported operations use the in-memory store instead.
 *
 * @implements ModelCacheService<int, Model>
 */
final class RedisModelCacheFake extends RedisModelService implements ModelCacheService
{
    /**
     * @var array<class-string<Model>, array<string, Model>>
     */
    private array $store = [];

    /**
     * @var array<string, int>
     */
    private array $calls = [];

    private function __construct() {}

    public static function fake(): self
    {
        $fake = new self;

        app()->instance(ModelCacheService::class, $fake);
        app()->instance(RedisModelService::class, $fake);

        return $fake;
    }

    public function store(Model $model, ?float $revalidationTime = null): void
    {
        $modelClass = $model::class;
        $key = $this->modelKey($model->getKey());

        $this->store[$modelClass][$key] = $model;
        $this->incrementCall($modelClass, 'store');
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    public function storeMany(Collection $models, ?float $revalidationTime = null): void
    {
        foreach ($models as $model) {
            $this->store($model, $revalidationTime);
        }
    }

    public function find(int|string $id): ?Model
    {
        $key = $this->modelKey($id);

        foreach ($this->store as $modelClass => $models) {
            if (isset($models[$key])) {
                $this->incrementCall($modelClass, 'find');

                return $models[$key];
            }
        }

        $this->incrementCall('*', 'find');

        return null;
    }

    /**
     * @return Collection<int, Model>
     */
    public function where(array $where, bool $hydrate = true, ?array $only = null): Collection
    {
        /** @var Collection<int, Model> $matches */
        $matches = collect();

        foreach ($this->store as $modelClass => $models) {
            $this->incrementCall($modelClass, 'where');

            foreach ($models as $key => $model) {
                if ($only !== null && $only !== [] && ! in_array($key, array_map(
                    fn (int|string $value): string => $this->modelKey($value),
                    $only
                ), true)) {
                    continue;
                }

                if ($this->matchesWhere($model, $where)) {
                    $matches->push($model);
                }
            }
        }

        return $matches;
    }

    public function delete(int|string $id): void
    {
        $key = $this->modelKey($id);
        $deleted = false;

        foreach ($this->store as $modelClass => &$models) {
            if (array_key_exists($key, $models)) {
                unset($models[$key]);
                $this->incrementCall($modelClass, 'delete');
                $deleted = true;
            }
        }
        unset($models);

        if (! $deleted) {
            $this->incrementCall('*', 'delete');
        }
    }

    /**
     * Return every model stored in the fake.
     *
     * @return Collection<int, Model>
     */
    public function all(bool $hydrate = true, ?array $only = null): Collection
    {
        /** @var Collection<int, Model> $models */
        $models = collect();

        foreach ($this->store as $modelClass => $storedModels) {
            $this->incrementCall($modelClass, 'all');

            foreach ($storedModels as $key => $model) {
                if ($only !== null && $only !== [] && ! in_array($key, array_map(
                    fn (int|string $value): string => $this->modelKey($value),
                    $only
                ), true)) {
                    continue;
                }

                $models->push($model);
            }
        }

        return $models;
    }

    public function assertStored(string $modelClass, int|string $id): void
    {
        Assert::assertArrayHasKey(
            $this->modelKey($id),
            $this->store[$modelClass] ?? [],
            "Failed asserting that {$modelClass} [{$id}] is stored."
        );
    }

    public function assertNotStored(string $modelClass, int|string $id): void
    {
        Assert::assertArrayNotHasKey(
            $this->modelKey($id),
            $this->store[$modelClass] ?? [],
            "Failed asserting that {$modelClass} [{$id}] is not stored."
        );
    }

    public function assertStoredCount(string $modelClass, int $count): void
    {
        Assert::assertCount(
            $count,
            $this->store[$modelClass] ?? [],
            "Failed asserting that {$modelClass} has {$count} stored model(s)."
        );
    }

    public function assertDeleted(string $modelClass, int|string $id): void
    {
        $this->assertNotStored($modelClass, $id);
    }

    public function assertNothingStored(): void
    {
        Assert::assertCount(0, $this->all(), 'Failed asserting that no models are stored.');
    }

    /**
     * @return Collection<int, Model>
     */
    public function storedFor(string $modelClass): Collection
    {
        return collect($this->store[$modelClass] ?? [])->values();
    }

    /**
     * Return the number of calls recorded for an operation.
     *
     * @internal Useful when a test needs to verify cache interaction.
     */
    public function callCount(string $modelClass, string $operation): int
    {
        return $this->calls[$this->callKey($modelClass, $operation)] ?? 0;
    }

    public function touchInvalidationTimestamp(): void {}

    public function removeCustomIndexes(int|string $id, array $attributes = []): void {}

    public function bustVersion(): void {}

    public function rememberAll(
        callable $callback,
        bool $hydrate = true,
        array $where = [],
        bool $refresh = false,
        ?array $only = null,
        bool $stampede = false,
        bool $swr = false,
        ?float $revalidationTime = null,
    ): Collection {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function remember(
        callable $callback,
        bool $refresh = false,
        string|Expression|null $findBy = null,
        mixed $findValue = null,
        string $findOperator = '=',
        ?float $revalidationTime = null,
    ): ?Model {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function rememberIndex(string $field, string|int $value, callable $callback, bool $hydrate = true): Collection
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function rememberCustom(string $name, callable $callback, bool $hydrate = true, ?string $sortBy = null, bool $refresh = false): Collection
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function updateAttribute(int|string $id, string $attribute, mixed $value): void
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function updateAttributes(int|string $id, array $attributes): void
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function whereIn(string $field, array $values, bool $hydrate = true, ?array $only = null): Collection|ExplainResult
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function whereBetween(
        string $field,
        int|float $min,
        int|float $max,
        bool $hydrate = true,
        ?array $only = null,
        ?int $limit = null,
        int $offset = 0,
    ): Collection|ExplainResult {
        $this->throwUnsupported(__FUNCTION__);
    }

    /**
     * @return LengthAwarePaginator<int, Model|string>
     */
    public function paginateWhereBetween(
        string $field,
        int|float $min,
        int|float $max,
        int $perPage = 15,
        int $page = 1,
        bool $hydrate = true,
    ): LengthAwarePaginator {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function orWhere(array $where, array $baseIds = [], bool $hydrate = true): Collection
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function pluck(array $attributes, array $where = [], ?array $only = null): Collection
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function selective(array $fields, array $where = [], ?array $only = null): Collection
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function clear(): void
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function clearAll(): void
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function debug(): static
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function inspect(int|string $id): ?array
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function analyzeIndexes(): array
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function first(array $where): ?Model
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function count(array $where): int
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    public function exists(array $where): bool
    {
        $this->throwUnsupported(__FUNCTION__);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function matchesWhere(Model $model, array $where): bool
    {
        foreach ($where as $attribute => $expected) {
            $actual = $model->getAttribute($attribute);

            if ($actual !== $expected && (string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    private function modelKey(int|string|null $id): string
    {
        return (string) $id;
    }

    private function callKey(string $modelClass, string $operation): string
    {
        return "{$modelClass}::{$operation}";
    }

    private function incrementCall(string $modelClass, string $operation): void
    {
        $key = $this->callKey($modelClass, $operation);
        $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;
    }

    private function throwUnsupported(string $method): never
    {
        throw new \BadMethodCallException(
            "Fake does not implement {$method}. Stub it in your test."
        );
    }
}
