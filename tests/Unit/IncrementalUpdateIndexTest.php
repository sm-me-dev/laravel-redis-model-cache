<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;
use Sm_mE\RedisModelCache\Tests\TestCase;

class IncrementalUpdateIndexTest extends TestCase
{
    protected RedisModelService $service;

    protected function setUp(): void
    {
        parent::setUp();

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
        // Manually clean up keys to avoid SCAN issue
        $redis = $this->service->getRedis();
        $redis->del(
            '{dummy_models}:hash',
            '{dummy_models}:meta',
            '{dummy_models}:index:status:active',
            '{dummy_models}:index:status:inactive',
            '{dummy_models}:index:name:John Doe',
            '{dummy_models}:index:name:Jane Doe',
            '{dummy_models}:index:name:John',
            '{dummy_models}:index:name:Zebra',
            '{dummy_models}:sorted:name'
        );
        parent::tearDown();
    }

    public function test_updates_index_when_indexed_field_changes(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Verify initial index
        $redis = $this->service->getRedis();
        $activeMembers = $redis->smembers('{dummy_models}:index:status:active');
        $inactiveMembers = $redis->smembers('{dummy_models}:index:status:inactive');

        $this->assertContains('1', $activeMembers);
        $this->assertNotContains('1', $inactiveMembers);

        // Update indexed field
        $this->service->updateAttribute(1, 'status', 'inactive');

        // Verify index updated
        $activeMembers = $redis->smembers('{dummy_models}:index:status:active');
        $inactiveMembers = $redis->smembers('{dummy_models}:index:status:inactive');

        $this->assertNotContains('1', $activeMembers); // Removed from old index
        $this->assertContains('1', $inactiveMembers); // Added to new index
    }

    public function test_does_not_update_index_when_non_indexed_field_changes(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Get index state before update
        $redis = $this->service->getRedis();
        $activeMembers = $redis->smembers('{dummy_models}:index:status:active');
        $this->assertContains('1', $activeMembers);

        // Update non-indexed field
        $this->service->updateAttribute(1, 'name', 'Jane Doe');

        // Verify index unchanged
        $activeMembersAfter = $redis->smembers('{dummy_models}:index:status:active');
        $this->assertContains('1', $activeMembersAfter);
        $this->assertEquals($activeMembers, $activeMembersAfter);
    }

    public function test_updates_multiple_indexes_when_multiple_indexed_fields_change(): void
    {
        // Create service with multiple indexes
        $service = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status', 'name'],
            'sorted' => [],
            'ttl' => 3600,
        ]);

        $model = new DummyModel;
        $model->id = 1;
        $model->name = 'John Doe';
        $model->status = 'active';
        $model->exists = true;

        $service->rememberAll(
            callback: fn () => collect([$model]),
            where: ['status' => 'active', 'name' => 'John Doe']
        );

        // Update both indexed fields
        $service->updateAttributes(1, [
            'status' => 'inactive',
            'name' => 'Jane Doe',
        ]);

        // Verify both indexes updated
        $redis = $service->getRedis();

        // Status index
        $this->assertNotContains('1', $redis->smembers('{dummy_models}:index:status:active'));
        $this->assertContains('1', $redis->smembers('{dummy_models}:index:status:inactive'));

        // Name index
        $this->assertNotContains('1', $redis->smembers('{dummy_models}:index:name:John Doe'));
        $this->assertContains('1', $redis->smembers('{dummy_models}:index:name:Jane Doe'));

        $service->clear();
    }

    public function test_cleans_up_stale_index_entries(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        $redis = $this->service->getRedis();

        // Verify model is in active index
        $this->assertContains('1', $redis->smembers('{dummy_models}:index:status:active'));

        // Change status from active to inactive
        $this->service->updateAttribute(1, 'status', 'inactive');

        // Verify stale index cleaned up
        $activeMembers = $redis->smembers('{dummy_models}:index:status:active');
        $this->assertNotContains('1', $activeMembers);
        $this->assertEmpty($activeMembers); // No other models in this test
    }

    public function test_does_not_create_stale_index_when_value_unchanged(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        $redis = $this->service->getRedis();

        // Update with same value
        $this->service->updateAttribute(1, 'status', 'active');

        // Index should remain unchanged (no duplicate removal/addition)
        $activeMembers = $redis->smembers('{dummy_models}:index:status:active');
        $this->assertContains('1', $activeMembers);
        $this->assertCount(1, $activeMembers);
    }

    public function test_applies_ttl_to_new_index_keys(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Update indexed field
        $this->service->updateAttribute(1, 'status', 'inactive');

        // Verify TTL set on new index key
        $redis = $this->service->getRedis();
        $ttl = $redis->ttl('{dummy_models}:index:status:inactive');

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
    }

    public function test_handles_null_to_value_index_change(): void
    {
        // Create model with null status
        $redis = $this->service->getRedis();
        $data = [
            'attributes' => [
                'id' => 1,
                'name' => 'John Doe',
                'status' => null,
            ],
            'relations' => [],
        ];
        $redis->hset('{dummy_models}:hash', '1', json_encode($data));

        // Update from null to a value
        $this->service->updateAttribute(1, 'status', 'active');

        // Verify added to new index (no stale cleanup since old was null)
        $activeMembers = $redis->smembers('{dummy_models}:index:status:active');
        $this->assertContains('1', $activeMembers);
    }

    public function test_handles_value_to_null_index_change(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        $redis = $this->service->getRedis();

        // Update from value to null
        $this->service->updateAttribute(1, 'status', null);

        // Verify removed from old index
        $activeMembers = $redis->smembers('{dummy_models}:index:status:active');
        $this->assertNotContains('1', $activeMembers);

        // Verify not added to any new index (null doesn't create index entry)
        $nullMembers = $redis->smembers('{dummy_models}:index:status:');
        $this->assertEmpty($nullMembers);
    }

    public function test_index_update_with_sorted_field_change(): void
    {
        // Create service with sorted field
        $service = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'sorted' => ['name'],
            'ttl' => 3600,
        ]);

        $model = new DummyModel;
        $model->id = 1;
        $model->name = 'John';
        $model->status = 'active';
        $model->exists = true;

        $service->rememberAll(
            callback: fn () => collect([$model]),
            where: ['status' => 'active']
        );

        // Update sorted field
        $service->updateAttribute(1, 'name', 'Zebra');

        // Verify sorted set updated
        $redis = $service->getRedis();
        $sortedKey = '{dummy_models}:sorted:name';
        $score = $redis->zscore($sortedKey, '1');

        $this->assertNotNull($score);

        $service->clear();
    }

    public function test_concurrent_index_updates_are_atomic(): void
    {
        $model1 = $this->createAndCacheModel(1, 'John', 'active');
        $model2 = $this->createAndCacheModel(2, 'Jane', 'active');

        // Both models in active index
        $redis = $this->service->getRedis();
        $activeMembers = $redis->smembers('{dummy_models}:index:status:active');
        $this->assertContains('1', $activeMembers);
        $this->assertContains('2', $activeMembers);

        // Update both models (simulating concurrent updates)
        $this->service->updateAttribute(1, 'status', 'inactive');
        $this->service->updateAttribute(2, 'status', 'inactive');

        // Verify both moved to inactive index
        $activeMembers = $redis->smembers('{dummy_models}:index:status:active');
        $inactiveMembers = $redis->smembers('{dummy_models}:index:status:inactive');

        $this->assertEmpty($activeMembers);
        $this->assertContains('1', $inactiveMembers);
        $this->assertContains('2', $inactiveMembers);
    }

    protected function createAndCacheModel(int $id, string $name, string $status): DummyModel
    {
        $model = new DummyModel;
        $model->id = $id;
        $model->name = $name;
        $model->status = $status;
        $model->exists = true;

        // Store directly via the public redis property to avoid warm-cache bypass
        $this->service->store($model);

        return $model;
    }
}
