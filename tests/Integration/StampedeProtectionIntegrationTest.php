<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Integration;

use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Support\StampedeProtection;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;

class StampedeProtectionIntegrationTest extends IntegrationTestCase
{
    private RedisModelService $service;

    private string $hashKey;

    private string $metaKey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('redis-model-cache.lua_scripting.enabled', false);
        config()->set('redis-model-cache.stampede_protection.enabled', true);
        config()->set('redis-model-cache.stampede_protection.lock_timeout', 10);
        config()->set('redis-model-cache.stampede_protection.wait_timeout', 1);
        config()->set('redis-model-cache.stampede_protection.wait_interval', 50);

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
        $redis->del($this->hashKey.':lock');

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

    public function test_lock_acquire_and_release_with_real_redis(): void
    {
        $lockKey = StampedeProtection::lockKey($this->hashKey);

        $acquired = StampedeProtection::acquireLock($this->service->getRedis(), $lockKey, 10);
        $this->assertTrue($acquired);

        $secondAttempt = StampedeProtection::acquireLock($this->service->getRedis(), $lockKey, 10);
        $this->assertFalse($secondAttempt);

        StampedeProtection::releaseLock($this->service->getRedis(), $lockKey);

        $reacquired = StampedeProtection::acquireLock($this->service->getRedis(), $lockKey, 10);
        $this->assertTrue($reacquired);

        StampedeProtection::releaseLock($this->service->getRedis(), $lockKey);
    }

    public function test_lock_auto_expires_after_timeout(): void
    {
        $lockKey = StampedeProtection::lockKey($this->hashKey);

        StampedeProtection::acquireLock($this->service->getRedis(), $lockKey, 1);
        $this->assertTrue((bool) $this->service->getRedis()->exists($lockKey));

        sleep(2);

        $this->assertFalse((bool) $this->service->getRedis()->exists($lockKey));
    }

    public function test_wait_for_lock_returns_true_when_lock_absent(): void
    {
        $lockKey = StampedeProtection::lockKey($this->hashKey);

        $result = StampedeProtection::waitForLock($this->service->getRedis(), $lockKey, 1, 50);

        $this->assertTrue($result);
    }

    public function test_wait_for_lock_returns_false_on_timeout(): void
    {
        $lockKey = StampedeProtection::lockKey($this->hashKey);

        StampedeProtection::acquireLock($this->service->getRedis(), $lockKey, 5);

        $result = StampedeProtection::waitForLock($this->service->getRedis(), $lockKey, 1, 100);

        $this->assertFalse($result);

        StampedeProtection::releaseLock($this->service->getRedis(), $lockKey);
    }

    public function test_stampede_lock_is_set_and_released_by_remember_all(): void
    {
        $lockKey = StampedeProtection::lockKey($this->hashKey);

        $this->assertFalse((bool) $this->service->getRedis()->exists($lockKey));

        $this->service->rememberAll(
            callback: fn () => collect([$this->createDummyModel(1, 'active')]),
            where: ['status' => 'active'],
            stampede: true,
        );

        $this->assertFalse((bool) $this->service->getRedis()->exists($lockKey));
    }

    public function test_remember_all_with_stampede_acquires_lock_when_cache_empty(): void
    {
        $lockKey = StampedeProtection::lockKey($this->hashKey);

        $this->service->rememberAll(
            callback: fn () => collect([$this->createDummyModel(1, 'active')]),
            where: ['status' => 'active'],
            stampede: true,
        );

        $this->assertNotNull($this->service->find(1));
        $this->assertFalse((bool) $this->service->getRedis()->exists($lockKey));
    }

    public function test_stampede_lock_in_use_causes_waiter_to_use_cached_result(): void
    {
        $this->service->rememberAll(
            callback: fn () => collect([$this->createDummyModel(1, 'active')]),
            where: ['status' => 'active'],
        );

        $lockKey = StampedeProtection::lockKey($this->hashKey);

        StampedeProtection::acquireLock($this->service->getRedis(), $lockKey, 10);

        $callCount = 0;
        $result = $this->service->rememberAll(
            callback: function () use (&$callCount) {
                $callCount++;

                return collect([$this->createDummyModel(1, 'active')]);
            },
            where: ['status' => 'active'],
            stampede: true,
        );

        $this->assertCount(1, $result);
        $this->assertSame(0, $callCount);

        StampedeProtection::releaseLock($this->service->getRedis(), $lockKey);
    }

    public function test_lock_key_generation(): void
    {
        $lockKey = StampedeProtection::lockKey($this->hashKey);
        $this->assertSame('{dummy_models}:hash:lock', $lockKey);
    }
}
