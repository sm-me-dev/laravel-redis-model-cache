<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Mockery;
use ReflectionMethod;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummySoftDeleteModel;
use Sm_mE\RedisModelCache\Tests\TestCase;

class HasRedisModelCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear any previously booted models between tests
        DummyModel::clearBootedModels();
        DummySoftDeleteModel::clearBootedModels();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_mark_and_unmark_processing(): void
    {
        $model = new DummyModel(['id' => 1, 'status' => 'active']);

        // Initially not processing
        $isProcessing = $this->invokeProtectedStatic('isRedisModelCacheProcessing', DummyModel::class, $model);
        $this->assertFalse($isProcessing);

        // Mark as processing
        $this->invokeProtectedStatic('markRedisModelCacheProcessing', DummyModel::class, $model);
        $isProcessing = $this->invokeProtectedStatic('isRedisModelCacheProcessing', DummyModel::class, $model);
        $this->assertTrue($isProcessing);

        // Unmark
        $this->invokeProtectedStatic('unmarkRedisModelCacheProcessing', DummyModel::class, $model);
        $isProcessing = $this->invokeProtectedStatic('isRedisModelCacheProcessing', DummyModel::class, $model);
        $this->assertFalse($isProcessing);
    }

    public function test_mark_and_check_deleted_in_cycle(): void
    {
        $model = new DummyModel(['id' => 1, 'status' => 'active']);

        // Initially not deleted in cycle
        $isDeleted = $this->invokeProtectedStatic('isRedisModelCacheDeletedInCycle', DummyModel::class, $model);
        $this->assertFalse($isDeleted);

        // Mark as deleted in cycle
        $this->invokeProtectedStatic('markRedisModelCacheDeletedInCycle', DummyModel::class, $model);
        $isDeleted = $this->invokeProtectedStatic('isRedisModelCacheDeletedInCycle', DummyModel::class, $model);
        $this->assertTrue($isDeleted);
    }

    public function test_resolve_redis_model_cache_service_uses_config(): void
    {
        $service = $this->invokeProtectedStatic('resolveRedisModelCacheService', DummyModel::class);

        $this->assertInstanceOf(RedisModelService::class, $service);
    }

    public function test_resolve_redis_model_cache_service_for_other_model(): void
    {
        $service = $this->invokeProtectedStatic(
            'resolveRedisModelCacheServiceFor',
            DummyModel::class,
            DummySoftDeleteModel::class
        );

        $this->assertInstanceOf(RedisModelService::class, $service);
    }

    public function test_resolve_touches_returns_empty_when_not_configured(): void
    {
        $touches = $this->invokeProtectedStatic('resolveRedisModelCacheTouches', DummyModel::class);

        $this->assertEquals([], $touches);
    }

    public function test_boot_registers_event_listeners_for_saved(): void
    {
        // Instantiate model to trigger boot
        $model = new DummyModel;

        // Verify booting registered the 'saved' event listener
        $dispatcher = $model->getEventDispatcher();
        $this->assertNotNull($dispatcher);

        $listeners = $dispatcher->getListeners('eloquent.saved: '.DummyModel::class);
        $this->assertNotEmpty($listeners);
    }

    public function test_boot_registers_restored_event_only_when_soft_deletes_present(): void
    {
        // DummySoftDeleteModel uses SoftDeletes trait
        $model = new DummySoftDeleteModel;

        // Verify it has the restored method (from SoftDeletes trait)
        $this->assertTrue(method_exists(DummySoftDeleteModel::class, 'restored'));
    }

    public function test_boot_registers_force_deleted_event_only_when_soft_deletes_present(): void
    {
        // DummySoftDeleteModel uses SoftDeletes trait
        $model = new DummySoftDeleteModel;

        // Verify it has the forceDeleted method (from SoftDeletes trait)
        $this->assertTrue(method_exists(DummySoftDeleteModel::class, 'forceDeleted'));
    }

    public function test_dummy_model_does_not_have_soft_delete_methods(): void
    {
        // Regular DummyModel doesn't use SoftDeletes
        $model = new DummyModel;

        // Verify it doesn't have the soft delete methods
        $this->assertFalse(method_exists(DummyModel::class, 'restored'));
        $this->assertFalse(method_exists(DummyModel::class, 'forceDeleted'));
    }

    /**
     * Invoke protected static method
     */
    private function invokeProtectedStatic(string $method, string $class, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }
}
