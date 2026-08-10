<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Contracts;

interface ModelMatchStrategy
{
    public function normalize(string $value): string;

    public function matches(mixed $modelValue, mixed $searchValue, string $operator): bool;
}
