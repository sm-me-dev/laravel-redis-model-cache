<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SmMe\RedisModelCache\RedisModelCacheServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            RedisModelCacheServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('redis-model-cache.connection', 'cache');
        $app['config']->set('redis-model-cache.scan_count', 1000);
        $app['config']->set('redis-model-cache.default_ttl', 86400);
    }
}
