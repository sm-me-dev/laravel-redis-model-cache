<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Contracts;

interface RedisConnectionResolver
{
    public function resolve(): mixed;

    public function getPrefix(): string;
}
