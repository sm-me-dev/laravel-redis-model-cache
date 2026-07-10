<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Integration;

use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;

class TtlExpiryIntegrationTest extends IntegrationTestCase
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
        $redis = $this->service->getRedis();
        $redis->del($this->hashKey, $this->metaKey);

        parent::tearDown();
    }

    private function createDummyModel(int $id, string $status): DummyModel
    {
        $model = new DummyModel;
        $model->id = $id;
        $model->name = "User {$id}";
        $model->status = $status;
        $model->exists = true;

        return $model;
    }

    public function test_ttl_is_set_on_hash_key(): void
    {
        $this->service->store($this->createDummyModel(1, 'active'));

        $ttl = $this->service->getRedis()->ttl($this->hashKey);

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
    }

    public function test_hash_expires_after_short_ttl(): void
    {
        $service = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'sorted' => [],
            'ttl' => 1,
        ]);

        $service->store($this->createDummyModel(1, 'active'));

        $this->assertNotNull($service->find(1));

        sleep(2);

        $this->assertNull($service->find(1));
    }

    public function test_remember_all_sets_cached_at_metadata(): void
    {
        $this->service->rememberAll(
            callback: fn () => collect([$this->createDummyModel(1, 'active')]),
            where: ['status' => 'active'],
        );

        $meta = $this->service->getRedis()->hget($this->metaKey, 'cached_at');
        $this->assertNotNull($meta);
        $this->assertGreaterThan(0, (int) $meta);
    }

    public function test_cache_is_stale_after_ttl_expiry(): void
    {
        $this->service->rememberAll(
            callback: fn () => collect([$this->createDummyModel(1, 'active')]),
            where: ['status' => 'active'],
        );

        $this->assertCount(1, $this->service->where(['status' => 'active']));

        $staleTime = time() - 7200;
        $this->service->getRedis()->hset($this->metaKey, 'cached_at', (string) $staleTime);

        $callCount = 0;
        $result = $this->service->rememberAll(
            callback: function () use (&$callCount) {
                $callCount++;

                return collect([$this->createDummyModel(1, 'active')]);
            },
            where: ['status' => 'active'],
            refresh: true,
        );

        $this->assertCount(1, $result);
    }

    public function test_meta_key_expires_with_hash(): void
    {
        $service = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'sorted' => [],
            'ttl' => 1,
        ]);

        $service->rememberAll(
            callback: fn () => collect([$this->createDummyModel(1, 'active')]),
            where: ['status' => 'active'],
        );

        $this->assertGreaterThan(0, $service->getRedis()->ttl($this->hashKey));
        $this->assertGreaterThan(0, $service->getRedis()->ttl($this->metaKey));
    }

    public function test_index_keys_expire_with_ttl_when_set_via_store(): void
    {
        config()->set('redis-model-cache.lua_scripting.enabled', true);

        $service = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'sorted' => [],
            'ttl' => 3600,
        ]);

        $service->store($this->createDummyModel(1, 'active'));

        $indexKey = '{dummy_models}:index:status:active';

        $this->assertTrue((bool) $service->getRedis()->exists($indexKey));

        $ttl = $service->getRedis()->ttl($indexKey);

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
    }

    public function test_ttl_set_on_custom_index_during_remember_custom(): void
    {
        $service = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'custom_indexes' => ['active' => ['status' => 'active']],
            'sorted' => [],
            'ttl' => 3600,
        ]);

        $service->rememberCustom(
            name: 'active',
            callback: fn () => collect([$this->createDummyModel(1, 'active')]),
        );

        $customKey = '{dummy_models}:custom:active';
        $this->assertGreaterThan(0, $service->getRedis()->ttl($customKey));
    }
}
