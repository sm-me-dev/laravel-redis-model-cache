<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Integration;

use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;

/**
 * Chaos-resilience tests verifying production survival under infrastructure failures.
 *
 * Coverage:
 * - Redis restart (SCRIPT FLUSH) → Lua EVAL fallback
 * - Lock TTL auto-release → stampede lock safety
 * - SWR freshness guard → stale write prevention
 * - Concurrent external modification → key consistency
 */
class ChaosResilienceIntegrationTest extends IntegrationTestCase
{
    private RedisModelService $service;

    private string $hashKey;

    private string $metaKey;

    protected function setUp(): void
    {
        parent::setUp();

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
        $redis->del($this->hashKey, $this->metaKey, '{dummy_models}:lock:stampede', '{dummy_models}:lock:swr');

        parent::tearDown();
    }

    // ── Redis restart: script cache flushed ──────────────────────────────

    public function test_lua_script_cache_flush_falls_back_to_eval(): void
    {
        $model = $this->makeModel(1, 'active');

        // Simulate Redis restart by flushing the script cache.
        // After this, all cached EVALSHA references will return NOSCRIPT,
        // forcing executeLua() to fall back to EVAL (which loads the script).
        // This also tests primeAtomicStoreScript() recovery via SCRIPT LOAD.
        $redis = $this->service->getRedis();
        $redis->script('flush');

        // Should successfully store despite flushed script cache
        $this->service->store($model);

        // Verify data was stored correctly
        $found = $this->service->find(1);
        $this->assertNotNull($found);
        $this->assertEquals('active', $found->status);
        $this->assertEquals('Test 1', $found->name);

        // Verify index was created correctly
        $result = $this->service->where(['status' => 'active']);
        $this->assertCount(1, $result);
    }

    public function test_lua_script_cache_flush_during_batch_store(): void
    {
        $models = collect([
            $this->makeModel(1, 'active'),
            $this->makeModel(2, 'inactive'),
        ]);

        $redis = $this->service->getRedis();
        $redis->script('flush');

        // storeMany uses a pipeline with EVALSHA; should recover via EVAL
        $this->service->storeMany($models);

        $result = $this->service->where(['status' => 'active']);
        $this->assertCount(1, $result);

        $result = $this->service->where(['status' => 'inactive']);
        $this->assertCount(1, $result);
    }

    // ── Lock TTL auto-release ───────────────────────────────────────────

    public function test_stampede_lock_auto_releases_via_ttl(): void
    {
        config()->set('redis-model-cache.lua_scripting.enabled', true);
        config()->set('redis-model-cache.stampede_protection.enabled', true);
        config()->set('redis-model-cache.stampede_protection.lock_timeout', 2);
        config()->set('redis-model-cache.stampede_protection.wait_timeout', 5);
        config()->set('redis-model-cache.stampede_protection.wait_interval', 100);

        $redis = $this->service->getRedis();
        $lockKey = '{dummy_models}:lock:stampede';

        // Manually set the stampede lock key with a short TTL
        // (simulating a crashed process that never releases)
        $redis->set($lockKey, 'crashed-process', ['NX', 'EX' => 2]);

        // Verify lock is held
        $this->assertSame(1, (int) $redis->exists($lockKey), 'Lock should be held after SET NX EX');

        // Wait for lock TTL to expire
        sleep(3);

        // Lock should have auto-released via TTL
        $this->assertSame(0, (int) $redis->exists($lockKey), 'Lock should have expired via TTL');

        // New request should succeed (lock expired, so first caller gets it)
        $result = $this->service->rememberAll(
            callback: fn () => collect([$this->makeModel(1, 'active')]),
            where: ['status' => 'active'],
            stampede: true,
        );
        $this->assertCount(1, $result);
    }

    public function test_stampede_lock_with_cas_release_external_expiry(): void
    {
        config()->set('redis-model-cache.lua_scripting.enabled', true);
        config()->set('redis-model-cache.stampede_protection.enabled', true);
        config()->set('redis-model-cache.stampede_protection.lock_timeout', 1);
        config()->set('redis-model-cache.stampede_protection.wait_timeout', 1);
        config()->set('redis-model-cache.stampede_protection.wait_interval', 50);

        $redis = $this->service->getRedis();
        $lockKey = '{dummy_models}:lock:stampede';

        // Manually set lock (simulating a crashed process without CAS release)
        $redis->set($lockKey, 'crashed-process', ['NX', 'EX' => 2]);

        // Let TTL expire (simulating process crash)
        sleep(3);

        // Lock should have auto-released via TTL
        $this->assertSame(-2, $redis->ttl($lockKey));

        // New request should succeed (lock available or CAS released)
        $result = $this->service->rememberAll(
            callback: fn () => collect([$this->makeModel(1, 'active')]),
            where: ['status' => 'active'],
            stampede: true,
        );
        $this->assertCount(1, $result);
    }

    // ── SWR freshness guard ─────────────────────────────────────────────

    public function test_swr_freshness_guard_prevents_stale_overwrite(): void
    {
        config()->set('redis-model-cache.lua_scripting.enabled', true);
        config()->set('redis-model-cache.stale_while_revalidate.enabled', true);

        $redis = $this->service->getRedis();

        // Store initial data
        $model1 = $this->makeModel(1, 'active');
        $this->service->store($model1);

        // Simulate the meta key having _last_invalidated_at
        // First, store cache metadata like normal
        $this->service->rememberAll(
            callback: fn () => collect([$model1]),
            where: ['status' => 'active'],
        );

        // Now manually set _last_invalidated_at to a time after any
        // potential revalidation (simulating a save happening DURING
        // the revalidation window)
        $invalidationTime = microtime(true);
        $redis->hset($this->metaKey, '_last_invalidated_at', (string) $invalidationTime);

        // Attempt to store a model with a revalidationTime BEFORE the
        // invalidation. The Lua script should detect this and skip the write.
        $olderRevalidationTime = $invalidationTime - 1.0;

        $model2 = $this->makeModel(2, 'active');
        $this->service->store($model2, $olderRevalidationTime);

        // The freshness guard should have prevented model2 from being stored
        // because _last_invalidated_at > revalidationToken
        $found = $this->service->find(2);
        $this->assertNull($found, 'Stale write should have been prevented by freshness guard');
    }

    public function test_swr_freshness_guard_allows_fresh_writes(): void
    {
        config()->set('redis-model-cache.lua_scripting.enabled', true);

        $redis = $this->service->getRedis();

        $model = $this->makeModel(1, 'active');
        $this->service->store($model);

        // Set _last_invalidated_at to a known time
        $invalidationTime = microtime(true);
        $redis->hset($this->metaKey, '_last_invalidated_at', (string) $invalidationTime);

        // Store with revalidationTime AFTER the invalidation
        // This should succeed because the revalidation is newer than the invalidation
        $newerRevalidationTime = $invalidationTime + 1.0;

        $model2 = $this->makeModel(2, 'inactive');
        $this->service->store($model2, $newerRevalidationTime);

        $found = $this->service->find(2);
        $this->assertNotNull($found, 'Fresh write should not be blocked by freshness guard');
        $this->assertEquals('inactive', $found->status);
    }

    // ── External cache modification ─────────────────────────────────────

    public function test_key_consistency_after_external_hash_modification(): void
    {
        $model = $this->makeModel(1, 'active');
        $this->service->store($model);

        // Simulate external process corrupting the hash
        $redis = $this->service->getRedis();
        $redis->hset($this->hashKey, '1', 'corrupted-data');

        // Should gracefully handle corruption
        $found = $this->service->find(1);
        $this->assertNull($found, 'Corrupted data should return null');

        // Index still points to ID 1, but find() returns null
        // This tests the resilience: corrupted entries are skipped silently
        $whereResult = $this->service->where(['status' => 'active']);
        $this->assertCount(0, $whereResult, 'Corrupted entries should be excluded from results');
    }

    public function test_key_consistency_after_external_key_deletion(): void
    {
        $model = $this->makeModel(1, 'active');
        $this->service->store($model);

        // Simulate external process deleting the hash key
        $redis = $this->service->getRedis();
        $redis->del($this->hashKey);

        // Index still exists, but hash is gone -> should return empty
        $result = $this->service->where(['status' => 'active']);
        $this->assertCount(0, $result);

        // Re-store should work (index gets rebuilt)
        $this->service->store($model);
        $found = $this->service->find(1);
        $this->assertNotNull($found);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makeModel(int $id, string $status): DummyModel
    {
        $model = new DummyModel;
        $model->id = $id;
        $model->name = "Test {$id}";
        $model->status = $status;
        $model->exists = true;

        return $model;
    }
}
