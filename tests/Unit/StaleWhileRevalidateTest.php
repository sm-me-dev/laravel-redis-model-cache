<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Illuminate\Support\Facades\Queue;
use Mockery;
use Sm_mE\RedisModelCache\Jobs\RevalidateCacheJob;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;
use Sm_mE\RedisModelCache\Tests\TestCase;

class StaleWhileRevalidateTest extends TestCase
{
    protected RedisModelService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('redis-model-cache.stale_while_revalidate.enabled', true);
        config()->set('redis-model-cache.stale_while_revalidate.grace_period', 300);
        config()->set('redis-model-cache.stale_while_revalidate.queue', 'default');

        $this->service = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'sorted' => [],
            'ttl' => 60, // 1 minute TTL
        ]);

        $this->service->clear();
    }

    protected function tearDown(): void
    {
        // Manually clean up keys to avoid SCAN issue
        $redis = $this->service->redis;
        $redis->del(
            '{dummy_models}:hash',
            '{dummy_models}:meta',
            '{dummy_models}:index:status:active',
            '{dummy_models}:index:status:inactive',
            '{dummy_models}:swr:lock'
        );
        Mockery::close();
        parent::tearDown();
    }

    public function test_stores_cache_metadata_when_storing_models(): void
    {
        $models = collect([
            $this->createDummyModel(1, 'active'),
            $this->createDummyModel(2, 'active'),
        ]);

        $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active']
        );

        // Verify metadata exists
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $cachedAt = $redis->hget($metaKey, 'cached_at');

        $this->assertNotNull($cachedAt);
        $this->assertIsNumeric($cachedAt);
        $this->assertGreaterThan(time() - 5, (int) $cachedAt);
    }

    public function test_check_stale_status_returns_not_stale_for_fresh_cache(): void
    {
        $models = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active']
        );

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkStaleStatus');
        $method->setAccessible(true);

        $status = $method->invoke($this->service);

        $this->assertFalse($status['is_stale']);
        $this->assertFalse($status['within_grace']);
        $this->assertFalse($status['should_revalidate']);
    }

    public function test_check_stale_status_detects_stale_within_grace_period(): void
    {
        $models = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active']
        );

        // Manually set cached_at to simulate stale but within grace
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $staleTime = time() - 80; // 80 seconds ago (TTL is 60s, grace is 300s)
        $redis->hset($metaKey, 'cached_at', (string) $staleTime);

        // Check status
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkStaleStatus');
        $method->setAccessible(true);

        $status = $method->invoke($this->service);

        $this->assertTrue($status['is_stale']);
        $this->assertTrue($status['within_grace']);
        $this->assertTrue($status['should_revalidate']);
    }

    public function test_check_stale_status_detects_expired_beyond_grace(): void
    {
        $models = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active']
        );

        // Manually set cached_at to simulate expired beyond grace
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $expiredTime = time() - 400; // 400 seconds ago (TTL 60s + grace 300s = 360s)
        $redis->hset($metaKey, 'cached_at', (string) $expiredTime);

        // Check status
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkStaleStatus');
        $method->setAccessible(true);

        $status = $method->invoke($this->service);

        $this->assertTrue($status['is_stale']);
        $this->assertFalse($status['within_grace']);
        $this->assertFalse($status['should_revalidate']);
    }

    public function test_swr_serves_stale_data_and_dispatches_job(): void
    {
        Queue::fake();

        $models = collect([
            $this->createDummyModel(1, 'active'),
            $this->createDummyModel(2, 'active'),
        ]);

        // Initial cache population
        $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active']
        );

        // Make cache stale but within grace period
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $staleTime = time() - 80; // Stale but within grace
        $redis->hset($metaKey, 'cached_at', (string) $staleTime);

        // Fetch with SWR enabled
        $result = $this->service->rememberAll(
            callback: fn () => collect([]), // Should not be called
            where: ['status' => 'active'],
            swr: true
        );

        // Should return stale data immediately
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result->first()->id);

        // Should dispatch revalidation job
        Queue::assertPushed(RevalidateCacheJob::class, function ($job) {
            return $job->queue === 'default';
        });
    }

    public function test_swr_does_not_dispatch_job_for_fresh_cache(): void
    {
        Queue::fake();

        $models = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        // Cache with fresh data
        $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active']
        );

        // Fetch with SWR enabled but cache is fresh
        $result = $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active'],
            swr: true
        );

        $this->assertCount(1, $result);

        // Should NOT dispatch job since cache is fresh
        Queue::assertNotPushed(RevalidateCacheJob::class);
    }

    public function test_swr_disabled_does_not_serve_stale_data(): void
    {
        config()->set('redis-model-cache.stale_while_revalidate.enabled', false);

        $initialModels = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        $updatedModels = collect([
            $this->createDummyModel(1, 'active'),
            $this->createDummyModel(2, 'active'),
        ]);

        // Initial cache
        $this->service->rememberAll(
            callback: fn () => $initialModels,
            where: ['status' => 'active']
        );

        // Make cache stale
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $staleTime = time() - 80;
        $redis->hset($metaKey, 'cached_at', (string) $staleTime);

        // Fetch with SWR enabled but config disabled
        $result = $this->service->rememberAll(
            callback: fn () => $updatedModels,
            where: ['status' => 'active'],
            swr: true
        );

        // Should return cached data (SWR disabled, no stale-serving magic,
        // hash still exists so normal cache hit returns original 1 model)
        $this->assertCount(1, $result);
    }

    public function test_swr_parameter_false_bypasses_stale_serving(): void
    {
        Queue::fake();

        $initialModels = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        $updatedModels = collect([
            $this->createDummyModel(1, 'active'),
            $this->createDummyModel(2, 'active'),
        ]);

        // Initial cache
        $this->service->rememberAll(
            callback: fn () => $initialModels,
            where: ['status' => 'active']
        );

        // Make cache stale
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $staleTime = time() - 80;
        $redis->hset($metaKey, 'cached_at', (string) $staleTime);

        // Fetch with SWR disabled
        $result = $this->service->rememberAll(
            callback: fn () => $updatedModels,
            where: ['status' => 'active'],
            swr: false
        );

        // Should return cached data (swr:false, hash still warm — normal cache hit, 1 model)
        $this->assertCount(1, $result);

        // Should NOT dispatch job
        Queue::assertNotPushed(RevalidateCacheJob::class);
    }

    protected function createDummyModel(int $id, string $status): DummyModel
    {
        $model = new DummyModel;
        $model->id = $id;
        $model->name = "Test User {$id}";
        $model->status = $status;
        $model->exists = true;

        return $model;
    }

    public function test_revalidate_cache_job_constructor_throws_invalid_argument_exception_on_non_serializable_closure(): void
    {
        // An anonymous class instance is not serializable
        $nonSerializable = new class {};

        $closure = function () use ($nonSerializable) {
            return $nonSerializable;
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to serialize SWR callback closure');

        new RevalidateCacheJob(
            modelClass: DummyModel::class,
            callback: $closure
        );
    }

    public function test_swr_prevents_duplicate_dispatches_using_lock(): void
    {
        Queue::fake();

        $initialModels = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        $updatedModels = collect([
            $this->createDummyModel(1, 'active'),
            $this->createDummyModel(2, 'active'),
        ]);

        $redis = $this->service->redis;
        $redis->del('{dummy_models}:swr:lock');

        // Populate cache
        $this->service->rememberAll(
            callback: fn () => $initialModels,
            where: ['status' => 'active']
        );

        // Make cache stale
        $metaKey = '{dummy_models}:meta';
        $staleTime = time() - 80;
        $redis->hset($metaKey, 'cached_at', (string) $staleTime);

        $this->service->rememberAll(
            callback: fn () => $updatedModels,
            where: ['status' => 'active'],
            swr: true
        );

        // Verify job was pushed
        Queue::assertPushed(RevalidateCacheJob::class, 1);

        // Second call - should NOT dispatch the job again (due to lock)
        $this->service->rememberAll(
            callback: fn () => $updatedModels,
            where: ['status' => 'active'],
            swr: true
        );

        // Verify job is still only pushed once
        Queue::assertPushed(RevalidateCacheJob::class, 1);

        // Manually release the lock (simulating job completing and releasing the lock)
        $redis->del('{dummy_models}:swr:lock');

        // Third call - should dispatch the job again
        $this->service->rememberAll(
            callback: fn () => $updatedModels,
            where: ['status' => 'active'],
            swr: true
        );

        // Verify job was pushed again (total 2 times now)
        Queue::assertPushed(RevalidateCacheJob::class, 2);
    }
}
