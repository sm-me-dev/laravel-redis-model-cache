<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Contracts;

interface RedisConnectionResolver
{
    public function resolve(): mixed;

    public function getPrefix(): string;
}
