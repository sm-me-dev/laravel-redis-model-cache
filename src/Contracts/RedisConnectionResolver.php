<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Contracts;

interface RedisConnectionResolver
{
    public function resolve(): mixed;

    public function getPrefix(): string;
}
