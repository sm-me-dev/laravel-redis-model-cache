<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Tests\Unit\Attributes;

use Mockery;
use ReflectionMethod;
use SMDev\RedisModelCache\RedisModelService;
use SMDev\RedisModelCache\Tests\Fixtures\CacheWithLifecycleModel;
use SMDev\RedisModelCache\Tests\TestCase;

class CacheWithTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_cache_with_relations_are_loaded_before_store(): void
    {
        $service = Mockery::mock(RedisModelService::class);
        $service->shouldReceive('store')->once();
        $service->shouldReceive('touchInvalidationTimestamp')->once();

        app()->bind(RedisModelService::class, static fn (): RedisModelService => $service);

        $model = new CacheWithLifecycleModel(['id' => 1]);

        $method = new ReflectionMethod(CacheWithLifecycleModel::class, 'processRedisModelCacheSaved');
        $method->setAccessible(true);
        $method->invoke(null, $model);

        $this->assertSame(['roles', 'permissions'], $model->loadedMissingRelations);
    }
}
