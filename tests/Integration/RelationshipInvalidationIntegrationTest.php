<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Integration;

use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummyModel;
use Sm_mE\RedisModelCache\Tests\Fixtures\DummySoftDeleteModel;

class RelationshipInvalidationIntegrationTest extends IntegrationTestCase
{
    private RedisModelService $parentService;

    private RedisModelService $childService;

    private RedisModelService $softDeleteService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parentService = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'sorted' => [],
            'ttl' => 3600,
        ]);

        $this->childService = app(RedisModelService::class, [
            'model_class' => DummyModel::class,
            'indexes' => ['status'],
            'sorted' => [],
            'ttl' => 3600,
        ]);

        $this->softDeleteService = app(RedisModelService::class, [
            'model_class' => DummySoftDeleteModel::class,
            'indexes' => ['status'],
            'sorted' => [],
            'ttl' => 3600,
        ]);
    }

    protected function tearDown(): void
    {
        $this->parentService->clear();
        $this->childService->clear();
        $this->softDeleteService->clear();

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

    private function createDummySoftDeleteModel(int $id, string $status, ?string $name = null): DummySoftDeleteModel
    {
        $model = new DummySoftDeleteModel;
        $model->id = $id;
        $model->name = $name ?? "User {$id}";
        $model->status = $status;
        $model->exists = true;

        return $model;
    }

    public function test_parent_model_update_affects_child_cache(): void
    {
        // Create parent and child models
        $parent = $this->createDummyModel(1, 'active', 'Parent');
        $child = $this->createDummyModel(2, 'active', 'Child');

        // Store parent and child
        $this->parentService->store($parent);
        $this->childService->store($child);

        // Verify initial state
        $this->assertEquals('Parent', $this->parentService->find(1)->name);
        $this->assertEquals('Child', $this->childService->find(2)->name);

        // Update parent
        $parent->name = 'Updated Parent';
        $parent->save();

        // Verify child cache is not affected
        $this->assertEquals('Child', $this->childService->find(2)->name);
    }

    public function test_child_model_update_affects_parent_cache(): void
    {
        // Create parent and child models
        $parent = $this->createDummyModel(1, 'active', 'Parent');
        $child = $this->createDummyModel(2, 'active', 'Child');

        // Store parent and child
        $this->parentService->store($parent);
        $this->childService->store($child);

        // Verify initial state
        $this->assertEquals('Parent', $this->parentService->find(1)->name);
        $this->assertEquals('Child', $this->childService->find(2)->name);

        // Update child
        $child->name = 'Updated Child';
        $child->save();

        // Verify parent cache is updated
        $this->assertEquals('Parent', $this->parentService->find(1)->name);
    }

    public function test_circular_relationship_invalidation(): void
    {
        // Create two models with circular reference
        $model1 = $this->createDummyModel(1, 'active', 'Model 1');
        $model2 = $this->createDummyModel(2, 'active', 'Model 2');

        // Store both models
        $this->parentService->store($model1);
        $this->childService->store($model2);

        // Verify initial state
        $this->assertEquals('Model 1', $this->parentService->find(1)->name);
        $this->assertEquals('Model 2', $this->childService->find(2)->name);

        // Update model1
        $model1->name = 'Updated Model 1';
        $model1->save();

        // Verify model2 cache is updated
        $this->assertEquals('Model 2', $this->childService->find(2)->name);

        // Update model2
        $model2->name = 'Updated Model 2';
        $model2->save();

        // Verify model1 cache is updated
        $this->assertEquals('Updated Model 1', $this->parentService->find(1)->name);
    }

    public function test_soft_delete_and_relationship_invalidation(): void
    {
        // Create soft delete model and related model
        $softDeleteModel = $this->createDummySoftDeleteModel(1, 'active', 'Soft Delete Model');
        $relatedModel = $this->createDummyModel(2, 'active', 'Related Model');

        // Store both models
        $this->softDeleteService->store($softDeleteModel);
        $this->parentService->store($relatedModel);

        // Verify initial state
        $this->assertEquals('Soft Delete Model', $this->softDeleteService->find(1)->name);
        $this->assertEquals('Related Model', $this->parentService->find(2)->name);

        // Soft delete the model
        $softDeleteModel->delete();

        // Verify related model cache is updated
        $this->assertEquals('Related Model', $this->parentService->find(2)->name);
    }

    public function test_bulk_update_and_relationship_invalidation(): void
    {
        // Create multiple models and save them to the database
        $models = [];
        for ($i = 1; $i <= 3; $i++) {
            $model = new DummyModel;
            $model->id = $i;
            $model->name = "Model {$i}";
            $model->status = 'active';
            $model->save();
            $models[] = $model;
        }

        // Verify initial state in cache
        foreach ($models as $model) {
            $this->assertEquals("Model {$model->id}", $this->parentService->find($model->id)->name);
        }

        // Bulk update
        DummyModel::whereIn('id', [1, 2, 3])->update(['status' => 'inactive']);

        // Since query builder bulk update does not fire Eloquent events,
        // we must manually clear/invalidate the cache.
        $this->parentService->clear();

        // Verify all caches are cleared/updated
        foreach ($models as $model) {
            $this->assertNull($this->parentService->find($model->id));
        }
    }
}
