<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Sm_mE\RedisModelCache\Jobs\RevalidateCacheJob;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;
use Sm_mE\RedisModelCache\Tests\TestCase;

class StaleWhileRevalidateIntegrationTest extends TestCase
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
            'ttl' => 60,
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
        parent::tearDown();
    }

    public function test_full_swr_flow_with_job_execution(): void
    {
        $initialModels = collect([
            $this->createDummyModel(1, 'active'),
            $this->createDummyModel(2, 'active'),
        ]);

        $updatedModels = collect([
            $this->createDummyModel(1, 'active'),
            $this->createDummyModel(2, 'active'),
            $this->createDummyModel(3, 'active'), // New model
        ]);

        // Step 1: Populate cache
        $result = $this->service->rememberAll(
            callback: fn () => $initialModels,
            where: ['status' => 'active']
        );

        $this->assertCount(2, $result);

        // Step 2: Make cache stale but within grace period
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $staleTime = time() - 80; // Stale (TTL 60s) but within grace (300s)
        $redis->hset($metaKey, 'cached_at', (string) $staleTime);

        // Step 3: Fetch with SWR - should return stale data immediately
        $staleResult = $this->service->rememberAll(
            callback: fn () => $updatedModels,
            where: ['status' => 'active'],
            swr: true
        );

        $this->assertCount(2, $staleResult); // Still returns old data

        // Step 4: Manually execute the revalidation job (simulate queue processing)
        $job = new RevalidateCacheJob(
            modelClass: DummyModel::class,
            callback: fn () => $updatedModels,
            where: ['status' => 'active'],
            indexes: ['status'],
            sorted: [],
            customIndexes: [],
            ttl: 60,
            redisConnection: null
        );

        $job->handle();

        // Step 5: Fetch again - should now return fresh data
        $freshResult = $this->service->rememberAll(
            callback: fn () => $updatedModels,
            where: ['status' => 'active'],
            swr: true
        );

        $this->assertCount(3, $freshResult); // Now has updated data with new model
    }

    public function test_swr_with_incremental_update_preserves_fresh_cache(): void
    {
        $models = collect([
            $this->createDummyModel(1, 'active'),
            $this->createDummyModel(2, 'active'),
        ]);

        // Cache models
        $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active']
        );

        // Incrementally update one model
        $this->service->updateAttribute(1, 'name', 'Updated User 1');

        // Make cache stale
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $staleTime = time() - 80;

        // Wait for the update to be stored first
        // (updateAttribute calls storeCacheMetadata which updates the timestamp)
        // So we need to manually set it to stale after the update
        $redis->hset($metaKey, 'cached_at', (string) $staleTime);

        // Fetch with SWR
        $result = $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active'],
            swr: true
        );

        // Should serve stale data (with incremental update)
        $this->assertCount(2, $result);

        // Verify the incremental update is in the served data
        $model1 = $result->firstWhere('id', 1);
        $this->assertEquals('Updated User 1', $model1->name);
    }

    public function test_concurrent_requests_during_stale_period(): void
    {
        Queue::fake();

        $models = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        // Cache models
        $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active']
        );

        // Make cache stale
        $redis = $this->service->redis;
        $redis->del('{dummy_models}:swr:lock');
        $metaKey = '{dummy_models}:meta';
        $staleTime = time() - 80;
        $redis->hset($metaKey, 'cached_at', (string) $staleTime);

        // Simulate 3 concurrent requests
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $this->service->rememberAll(
                callback: fn () => $models,
                where: ['status' => 'active'],
                swr: true
            );
        }

        // All should return stale data immediately
        foreach ($results as $result) {
            $this->assertCount(1, $result);
        }

        // Only one job should be dispatched due to SWR lock deduplication
        Queue::assertPushed(RevalidateCacheJob::class, 1);
    }

    public function test_swr_respects_ttl_expiration_beyond_grace(): void
    {
        $initialModels = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        $updatedModels = collect([
            $this->createDummyModel(1, 'active'),
            $this->createDummyModel(2, 'active'),
        ]);

        // Cache models
        $this->service->rememberAll(
            callback: fn () => $initialModels,
            where: ['status' => 'active']
        );

        // Make cache expired beyond grace period
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $expiredTime = time() - 400; // Beyond TTL (60s) + grace (300s)
        $redis->hset($metaKey, 'cached_at', (string) $expiredTime);

        // Fetch with SWR - should execute callback and return fresh data
        $result = $this->service->rememberAll(
            callback: fn () => $updatedModels,
            where: ['status' => 'active'],
            swr: true
        );

        $this->assertCount(2, $result); // Returns fresh data from callback
    }

    public function test_swr_with_empty_stale_cache_executes_callback(): void
    {
        $models = collect([
            $this->createDummyModel(1, 'active'),
        ]);

        // Fetch with SWR but no cache exists
        $result = $this->service->rememberAll(
            callback: fn () => $models,
            where: ['status' => 'active'],
            swr: true
        );

        $this->assertCount(1, $result);

        // Verify cache was populated
        $redis = $this->service->redis;
        $data = $redis->hget('{dummy_models}:hash', '1');
        $this->assertNotNull($data);
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
}
