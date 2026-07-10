<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Integration;

use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;

class BasicLifecycleIntegrationTest extends IntegrationTestCase
{
    private RedisModelService $service;

    private string $hashKey;

    private string $metaKey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('redis-model-cache.lua_scripting.enabled', false);

        $this->service = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'sorted' => [],
            'ttl' => 3600,
        ]);

        $this->hashKey = '{dummy_models}:hash';
        $this->metaKey = '{dummy_models}:meta';

        $this->service->clear();
    }

    protected function tearDown(): void
    {
        $redis = $this->service->redis;
        $redis->del($this->hashKey, $this->metaKey);

        parent::tearDown();
    }

    private function createDummyModel(int $id, string $status, ?string $name = null): DummyModel
    {
        $model = new DummyModel;
        $model->id = $id;
        $model->name = $name ?? "User {$id}";
        $model->status = $status;
        $model->exists = true;

        return $model;
    }

    public function test_store_and_retrieve_model(): void
    {
        $model = $this->createDummyModel(1, 'active', 'Alice');

        $this->service->store($model);

        $found = $this->service->find(1);
        $this->assertNotNull($found);
        $this->assertEquals('Alice', $found->name);
        $this->assertEquals('active', $found->status);
    }

    public function test_where_returns_matching_models(): void
    {
        $this->service->store($this->createDummyModel(1, 'active', 'Alice'));
        $this->service->store($this->createDummyModel(2, 'active', 'Bob'));
        $this->service->store($this->createDummyModel(3, 'inactive', 'Charlie'));

        $result = $this->service->where(['status' => 'active']);

        $this->assertCount(2, $result);
        $names = $result->pluck('name')->sort()->values()->toArray();
        $this->assertSame(['Alice', 'Bob'], $names);
    }

    public function test_delete_removes_model(): void
    {
        $this->service->store($this->createDummyModel(1, 'active'));

        $this->assertNotNull($this->service->find(1));

        $this->service->delete(1);

        $this->assertNull($this->service->find(1));
    }

    public function test_delete_removes_from_index(): void
    {
        $this->service->store($this->createDummyModel(1, 'active'));
        $this->service->store($this->createDummyModel(2, 'active'));

        $this->assertCount(2, $this->service->where(['status' => 'active']));

        $this->service->delete(1);

        $this->assertCount(1, $this->service->where(['status' => 'active']));
        $this->assertEquals(2, $this->service->where(['status' => 'active'])->first()->id);
    }

    public function test_update_attribute_reflects_in_cache(): void
    {
        $this->service->store($this->createDummyModel(1, 'active', 'Alice'));

        $this->service->updateAttribute(1, 'name', 'Updated Alice');

        $found = $this->service->find(1);
        $this->assertEquals('Updated Alice', $found->name);
        $this->assertEquals('active', $found->status);
    }

    public function test_remember_all_executes_callback_and_caches(): void
    {
        $result = $this->service->rememberAll(
            callback: fn () => collect([
                $this->createDummyModel(1, 'active'),
                $this->createDummyModel(2, 'active'),
            ]),
            where: ['status' => 'active'],
        );

        $this->assertCount(2, $result);

        $cached = $this->service->where(['status' => 'active']);
        $this->assertCount(2, $cached);
    }

    public function test_remember_all_serves_from_cache_on_subsequent_call(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return collect([$this->createDummyModel(1, 'active')]);
        };

        $this->service->rememberAll(callback: $callback, where: ['status' => 'active']);
        $this->assertSame(1, $callCount);

        $result = $this->service->rememberAll(callback: $callback, where: ['status' => 'active']);
        $this->assertCount(1, $result);
        $this->assertSame(1, $callCount);
    }

    public function test_store_updates_index_when_status_changes(): void
    {
        $this->service->store($this->createDummyModel(1, 'active'));

        $this->assertCount(1, $this->service->where(['status' => 'active']));
        $this->assertCount(0, $this->service->where(['status' => 'inactive']));

        $updatedModel = $this->createDummyModel(1, 'inactive');
        $this->service->store($updatedModel);

        $this->assertCount(0, $this->service->where(['status' => 'active']));
        $this->assertCount(1, $this->service->where(['status' => 'inactive']));
    }

    public function test_find_returns_null_for_nonexistent_model(): void
    {
        $this->assertNull($this->service->find(999));
    }

    public function test_clear_removes_all_cache_data(): void
    {
        $this->service->store($this->createDummyModel(1, 'active'));
        $this->service->store($this->createDummyModel(2, 'inactive'));

        $this->service->clear();

        $this->assertNull($this->service->find(1));
        $this->assertNull($this->service->find(2));
        $this->assertCount(0, $this->service->where(['status' => 'active']));
        $this->assertCount(0, $this->service->where(['status' => 'inactive']));
    }

    public function test_count_returns_correct_number(): void
    {
        $this->service->store($this->createDummyModel(1, 'active'));
        $this->service->store($this->createDummyModel(2, 'active'));
        $this->service->store($this->createDummyModel(3, 'inactive'));

        // We need to call refresh/index rebuild to update counts after storing
        usleep(200000);

        $this->assertSame(2, $this->service->count(['status' => 'active']));
        $this->assertSame(1, $this->service->count(['status' => 'inactive']));
    }
}
