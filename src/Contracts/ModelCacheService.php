<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Contracts;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @template TKey of array-key
 * @template TModel of Model
 */
interface ModelCacheService
{
    /**
     * @param  array<string>|null  $only
     * @return Collection<int, Model>
     */
    public function all(bool $hydrate = true, ?array $only = null): Collection;

    /**
     * @param  array<string, mixed>  $where
     * @param  array<string>|null  $only
     * @return Collection<int, Model>
     */
    public function where(array $where, bool $hydrate = true, ?array $only = null): Collection;

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

    public function clear(): void;

    public function clearAll(): void;
}
