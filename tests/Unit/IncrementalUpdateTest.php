<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use InvalidArgumentException;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;
use Sm_mE\RedisModelCache\Tests\TestCase;

class IncrementalUpdateTest extends TestCase
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
        // Manually clean up keys to avoid SCAN issue in pipeline mode
        $redis = $this->service->redis;
        $redis->del(
            '{dummy_models}:hash',
            '{dummy_models}:meta',
            '{dummy_models}:index:status:active',
            '{dummy_models}:index:status:inactive'
        );
        parent::tearDown();
    }

    public function test_update_attribute_modifies_single_attribute(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Update single attribute
        $this->service->updateAttribute(1, 'name', 'Jane Doe');

        // Verify the change
        $redis = $this->service->redis;
        $data = json_decode($redis->hget('{dummy_models}:hash', '1'), true);

        $this->assertEquals('Jane Doe', $data['attributes']['name']);
        $this->assertEquals('active', $data['attributes']['status']); // Unchanged
    }

    public function test_update_attributes_modifies_multiple_attributes(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Update multiple attributes
        $this->service->updateAttributes(1, [
            'name' => 'Jane Doe',
            'status' => 'inactive',
        ]);

        // Verify the changes
        $redis = $this->service->redis;
        $data = json_decode($redis->hget('{dummy_models}:hash', '1'), true);

        $this->assertEquals('Jane Doe', $data['attributes']['name']);
        $this->assertEquals('inactive', $data['attributes']['status']);
    }

    public function test_update_attribute_throws_exception_for_non_existent_model(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found in cache');

        $this->service->updateAttribute(999, 'name', 'Test');
    }

    public function test_update_attributes_throws_exception_for_non_existent_model(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found in cache');

        $this->service->updateAttributes(999, ['name' => 'Test']);
    }

    public function test_update_attribute_throws_exception_for_invalid_attribute(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist on model');

        $this->service->updateAttribute(1, 'nonexistent_field', 'value');
    }

    public function test_update_attributes_throws_exception_for_invalid_attribute(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist on model');

        $this->service->updateAttributes(1, [
            'name' => 'Valid',
            'invalid_field' => 'Invalid',
        ]);
    }

    public function test_update_preserves_relations(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Manually add a relation to the cached data
        $redis = $this->service->redis;
        $data = json_decode($redis->hget('{dummy_models}:hash', '1'), true);
        $data['relations'] = [
            'posts' => [
                ['id' => 1, 'title' => 'Post 1'],
                ['id' => 2, 'title' => 'Post 2'],
            ],
        ];
        $redis->hset('{dummy_models}:hash', '1', json_encode($data));

        // Update attribute
        $this->service->updateAttribute(1, 'name', 'Jane Doe');

        // Verify relations are preserved
        $updatedData = json_decode($redis->hget('{dummy_models}:hash', '1'), true);

        $this->assertArrayHasKey('relations', $updatedData);
        $this->assertCount(2, $updatedData['relations']['posts']);
        $this->assertEquals('Post 1', $updatedData['relations']['posts'][0]['title']);
    }

    public function test_update_attribute_updates_cache_metadata(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Get initial metadata timestamp
        $redis = $this->service->redis;
        $metaKey = '{dummy_models}:meta';
        $initialCachedAt = $redis->hget($metaKey, 'cached_at');

        // Wait a moment to ensure timestamp changes
        sleep(1);

        // Update attribute
        $this->service->updateAttribute(1, 'name', 'Jane Doe');

        // Verify metadata was updated
        $updatedCachedAt = $redis->hget($metaKey, 'cached_at');

        $this->assertNotEquals($initialCachedAt, $updatedCachedAt);
        $this->assertGreaterThan((int) $initialCachedAt, (int) $updatedCachedAt);
    }

    public function test_update_attributes_is_atomic_with_pipeline(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Update multiple attributes
        $this->service->updateAttributes(1, [
            'name' => 'Jane Doe',
            'status' => 'inactive',
        ]);

        // All changes should be applied atomically
        $redis = $this->service->redis;
        $data = json_decode($redis->hget('{dummy_models}:hash', '1'), true);

        $this->assertEquals('Jane Doe', $data['attributes']['name']);
        $this->assertEquals('inactive', $data['attributes']['status']);
    }

    public function test_update_attribute_with_null_value(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Update attribute to null
        $this->service->updateAttribute(1, 'name', null);

        // Verify the change
        $redis = $this->service->redis;
        $data = json_decode($redis->hget('{dummy_models}:hash', '1'), true);

        $this->assertNull($data['attributes']['name']);
    }

    public function test_update_attributes_with_mixed_types(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Add an id attribute to the data
        $redis = $this->service->redis;
        $data = json_decode($redis->hget('{dummy_models}:hash', '1'), true);
        $data['attributes']['id'] = 1;
        $redis->hset('{dummy_models}:hash', '1', json_encode($data));

        // Update with various types
        $this->service->updateAttributes(1, [
            'name' => 'Updated Name',
            'id' => 1,
        ]);

        // Verify all types preserved
        $updatedData = json_decode($redis->hget('{dummy_models}:hash', '1'), true);

        $this->assertIsString($updatedData['attributes']['name']);
        $this->assertIsInt($updatedData['attributes']['id']);
    }

    public function test_update_attribute_applies_ttl(): void
    {
        $model = $this->createAndCacheModel(1, 'John Doe', 'active');

        // Update attribute
        $this->service->updateAttribute(1, 'name', 'Jane Doe');

        // Verify TTL is set on hash key
        $redis = $this->service->redis;
        $ttl = $redis->ttl('{dummy_models}:hash');

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
    }

    public function test_update_supports_old_data_format_without_relations(): void
    {
        // Create model with old format (no relations key)
        $redis = $this->service->redis;
        $oldFormatData = [
            'id' => 1,
            'name' => 'John Doe',
            'status' => 'active',
        ];
        $redis->hset('{dummy_models}:hash', '1', json_encode($oldFormatData));

        // Update should work with old format
        $this->service->updateAttribute(1, 'name', 'Jane Doe');

        // Verify update worked
        $data = json_decode($redis->hget('{dummy_models}:hash', '1'), true);
        $this->assertEquals('Jane Doe', $data['attributes']['name']);
    }

    protected function createAndCacheModel(int $id, string $name, string $status): DummyModel
    {
        $model = new DummyModel;
        $model->id = $id;
        $model->name = $name;
        $model->status = $status;
        $model->exists = true;

        // Cache the model using rememberAll
        $this->service->rememberAll(
            callback: fn () => collect([$model]),
            where: ['status' => $status]
        );

        return $model;
    }
}
