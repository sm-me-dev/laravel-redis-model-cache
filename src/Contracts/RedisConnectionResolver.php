<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Contracts;

interface RedisConnectionResolver
{
    public function resolve(): mixed;

    public function getPrefix(): string;
}
