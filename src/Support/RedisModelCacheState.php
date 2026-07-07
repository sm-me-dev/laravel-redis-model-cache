<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class RedisModelCacheState
{
    /** @var array<class-string, list<mixed>> */
    protected array $processing = [];

    /** @var array<class-string, list<mixed>> */
    protected array $deletedInCycle = [];

    /** @param class-string $modelClass */
    public function isProcessing(string $modelClass, mixed $key): bool
    {
        return in_array($key, $this->processing[$modelClass] ?? [], true);
    }

    /** @param class-string $modelClass */
    public function markProcessing(string $modelClass, mixed $key): void
    {
        $ids = $this->processing[$modelClass] ?? [];
        $ids[] = $key;
        $this->processing[$modelClass] = $ids;
    }

    /** @param class-string $modelClass */
    public function unmarkProcessing(string $modelClass, mixed $key): void
    {
        $ids = $this->processing[$modelClass] ?? [];
        $index = array_search($key, $ids, true);

        if ($index !== false) {
            unset($ids[$index]);
            $this->processing[$modelClass] = array_values($ids);
        }
    }

    /** @param class-string $modelClass */
    public function isDeletedInCycle(string $modelClass, mixed $key): bool
    {
        return in_array($key, $this->deletedInCycle[$modelClass] ?? [], true);
    }

    /** @param class-string $modelClass */
    public function markDeletedInCycle(string $modelClass, mixed $key): void
    {
        $ids = $this->deletedInCycle[$modelClass] ?? [];
        $ids[] = $key;
        $this->deletedInCycle[$modelClass] = $ids;
    }

    public function flush(): void
    {
        $this->processing = [];
        $this->deletedInCycle = [];
    }
}
