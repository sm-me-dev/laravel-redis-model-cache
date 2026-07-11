<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;
use Sm_mE\RedisModelCache\Tests\TestCase;

class CriticalRaceTest extends TestCase
{
    protected RedisModelService $service;

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

        $this->service->clear();
    }

    protected function tearDown(): void
    {
        $redis = $this->service->getRedis();
        $redis->del(
            '{dummy_models}:hash',
            '{dummy_models}:meta',
            '{dummy_models}:index:status:active',
            '{dummy_models}:index:status:inactive',
        );
        parent::tearDown();
    }

    public function test_store_same_model_twice_updates_without_corruption(): void
    {
        $modelV1 = $this->makeModel(1, 'active', 'v1');
        $modelV2 = $this->makeModel(1, 'active', 'v2');

        // Simulate a cache miss by using a different where clause each time
        $this->service->rememberAll(
            callback: fn () => collect([$modelV1]),
            where: ['status' => 'active'],
        );

        // Clear cache so second store hits a cache miss
        $this->service->clear();

        $this->service->rememberAll(
            callback: fn () => collect([$modelV2]),
            where: ['status' => 'active'],
        );

        $redis = $this->service->getRedis();
        $payload = $redis->hget('{dummy_models}:hash', '1');

        $this->assertNotNull($payload);
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('v2', $decoded['attributes']['name'] ?? null);
    }

    public function test_delete_then_store_recreates_entry(): void
    {
        $model = $this->makeModel(1, 'active', 'test');

        $this->service->rememberAll(
            callback: fn () => collect([$model]),
            where: ['status' => 'active'],
        );

        $redis = $this->service->getRedis();
        $redis->del('{dummy_models}:hash');
        $redis->srem('{dummy_models}:index:status:active', '1');

        $model2 = $this->makeModel(1, 'active', 'recreated');
        $this->service->rememberAll(
            callback: fn () => collect([$model2]),
            where: ['status' => 'active'],
        );

        $payload = $redis->hget('{dummy_models}:hash', '1');
        $this->assertNotNull($payload);
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('recreated', $decoded['attributes']['name'] ?? null);
    }

    public function test_store_many_with_empty_collection_is_noop(): void
    {
        $redis = $this->service->getRedis();
        $redis->del('{dummy_models}:hash');

        $this->service->rememberAll(
            callback: fn () => collect([]),
            where: ['status' => 'active'],
        );

        $this->assertFalse($redis->hget('{dummy_models}:hash', '1'));
    }

    public function test_where_with_partial_index_returns_only_cached(): void
    {
        $model1 = $this->makeModel(1, 'active', 'a');
        $model2 = $this->makeModel(2, 'active', 'b');

        $this->service->rememberAll(
            callback: fn () => collect([$model1, $model2]),
            where: ['status' => 'active'],
        );

        $redis = $this->service->getRedis();
        $redis->hdel('{dummy_models}:hash', '2');

        $result = $this->service->where(['status' => 'active']);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result->first()->id);
    }

    public function test_store_with_changed_index_cleans_old_index(): void
    {
        $model = $this->makeModel(1, 'active', 'original');

        $this->service->rememberAll(
            callback: fn () => collect([$model]),
            where: ['status' => 'active'],
        );

        $redis = $this->service->getRedis();
        $this->assertTrue((bool) $redis->sismember('{dummy_models}:index:status:active', '1'));

        $model->status = 'inactive';
        $this->service->rememberAll(
            callback: fn () => collect([$model]),
            where: ['status' => 'inactive'],
        );

        $this->assertFalse($redis->sismember('{dummy_models}:index:status:active', '1'));
        $this->assertTrue((bool) $redis->sismember('{dummy_models}:index:status:inactive', '1'));
    }

    public function test_where_with_stale_index_returns_only_extant_entries(): void
    {
        $model1 = $this->makeModel(1, 'active', 'a');
        $model2 = $this->makeModel(2, 'active', 'b');

        $this->service->rememberAll(
            callback: fn () => collect([$model1, $model2]),
            where: ['status' => 'active'],
        );

        $redis = $this->service->getRedis();
        $redis->sadd('{dummy_models}:index:status:active', '999');
        $redis->hdel('{dummy_models}:hash', '2');

        $result = $this->service->where(['status' => 'active']);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result->first()->id);
    }

    protected function makeModel(int $id, string $status, string $name): DummyModel
    {
        $model = new DummyModel;
        $model->id = $id;
        $model->name = $name;
        $model->status = $status;
        $model->exists = true;

        return $model;
    }
}
