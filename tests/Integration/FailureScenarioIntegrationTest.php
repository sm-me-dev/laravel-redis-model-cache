<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Integration;

use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;

class FailureScenarioIntegrationTest extends IntegrationTestCase
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
            'sorted' => ['created_at'],
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

    private function createDummyModel(int $id, string $status): DummyModel
    {
        $model = new DummyModel;
        $model->id = $id;
        $model->name = "User {$id}";
        $model->status = $status;
        $model->created_at = time();
        $model->exists = true;

        return $model;
    }

    public function test_corrupted_json_in_hash_returns_null_on_find(): void
    {
        $this->service->redis->hset($this->hashKey, '1', 'not-valid-json');

        $result = $this->service->find(1);

        $this->assertNull($result);
    }

    public function test_invalid_json_in_hash_skipped_during_where(): void
    {
        $this->service->store($this->createDummyModel(1, 'active'));
        $this->service->redis->hset($this->hashKey, '2', '{corrupted:');

        $result = $this->service->where(['status' => 'active']);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result->first()->id);
    }

    public function test_empty_hash_returns_empty_collection(): void
    {
        $result = $this->service->where(['status' => 'active']);

        $this->assertCount(0, $result);
    }

    public function test_delete_of_nonexistent_id_does_not_throw(): void
    {
        $this->service->delete(999);

        $this->assertTrue(true);
    }

    public function test_find_on_empty_hash_returns_null(): void
    {
        $result = $this->service->find(1);

        $this->assertNull($result);
    }

    public function test_count_on_empty_index_returns_zero(): void
    {
        $result = $this->service->count(['status' => 'nonexistent']);

        $this->assertSame(0, $result);
    }

    public function test_inspect_returns_null_for_nonexistent_model(): void
    {
        $result = $this->service->inspect(999);

        $this->assertNull($result);
    }

    public function test_update_attribute_on_nonexistent_model_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->updateAttribute(999, 'name', 'Ghost');
    }

    public function test_bust_version_increments_counter(): void
    {
        $version = $this->service->redis->hget($this->metaKey, 'version');
        $this->assertFalse($version);

        $this->service->bustVersion();

        $version = $this->service->redis->hget($this->metaKey, 'version');
        $this->assertSame('1', $version);

        $this->service->bustVersion();

        $version = $this->service->redis->hget($this->metaKey, 'version');
        $this->assertSame('2', $version);
    }

    public function test_clear_all_removes_index_and_sorted_keys(): void
    {
        $this->service->store($this->createDummyModel(1, 'active'));

        $this->service->clearAll();

        $this->assertNull($this->service->find(1));
        $this->assertCount(0, $this->service->where(['status' => 'active']));
    }

    public function test_pluck_returns_only_requested_fields(): void
    {
        $this->service->store($this->createDummyModel(1, 'active'));

        $result = $this->service->pluck(['id', 'name'], ['status' => 'active']);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('User 1', $result[0]['name']);
        $this->assertArrayNotHasKey('status', $result[0]);
    }

    public function test_store_model_with_missing_index_field_does_not_add_to_index(): void
    {
        $model = new DummyModel;
        $model->id = 1;
        $model->name = 'No Status';
        $model->exists = true;

        $this->service->store($model);

        $found = $this->service->find(1);
        $this->assertNotNull($found);
        $this->assertEquals('No Status', $found->name);

        $this->assertCount(0, $this->service->where(['status' => 'active']));
        $this->assertCount(0, $this->service->where(['status' => 'inactive']));
    }

    public function test_delete_model_removes_from_sorted_set(): void
    {
        $model = new DummyModel;
        $model->id = 1;
        $model->name = 'Scored User';
        $model->status = 'active';
        $model->created_at = 1000;
        $model->exists = true;

        $this->service->store($model);

        $sortedKey = '{dummy_models}:sorted:created_at';
        $this->assertSame(1, $this->service->redis->zcard($sortedKey));

        $this->service->delete(1);

        $this->assertNull($this->service->find(1));
        $this->assertSame(0, $this->service->redis->zcard($sortedKey));
    }
}
