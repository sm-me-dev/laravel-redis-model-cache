<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;

class DefaultModelMatchStrategy implements ModelMatchStrategy
{
    public function normalize(string $value): string
    {
        return mb_strtolower($value);
    }

    public function matches(mixed $modelValue, mixed $searchValue, string $operator): bool
    {
        $normalizedModelValue = is_string($modelValue) ? $this->normalize($modelValue) : $modelValue;
        $normalizedSearchValue = is_string($searchValue) ? $this->normalize(trim($searchValue, '%')) : $searchValue;

        return match ($operator) {
            'ilike', 'like' => str_contains((string) $normalizedModelValue, (string) $normalizedSearchValue),
            '=' => $normalizedModelValue == $normalizedSearchValue,
            '!=' => $normalizedModelValue != $normalizedSearchValue,
            '>' => $normalizedModelValue > $normalizedSearchValue,
            '>=' => $normalizedModelValue >= $normalizedSearchValue,
            '<' => $normalizedModelValue < $normalizedSearchValue,
            '<=' => $normalizedModelValue <= $normalizedSearchValue,
            default => false,
        };
    }
}
