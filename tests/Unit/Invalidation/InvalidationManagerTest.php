<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Invalidation;

use Illuminate\Database\Eloquent\Model;
use Mockery;
use Mockery\MockInterface;
use Sm_mE\RedisModelCache\Invalidation\InvalidationManager;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\TestCase;

class InvalidationManagerTest extends TestCase
{
    private RedisModelService|MockInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Mockery::mock(RedisModelService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sync_strategy_calls_delete_on_deleted_event(): void
    {
        $model = new InvalidationTestModel(['id' => 5, 'role_id' => 2, 'status' => 'active']);

        $this->service->shouldReceive('delete')->with(5)->once();
        $this->service->shouldReceive('removeCustomIndexes')->with(5, $model->getAttributes())->once();

        $manager = new InvalidationManager(
            service: $this->service,
            strategy: 'sync',
            versioned: false,
        );

        $manager->handle('deleted', $model);

        $this->addToAssertionCount(1);
    }

    public function test_sync_strategy_bumps_version_when_versioned_enabled(): void
    {
        $model = new InvalidationTestModel(['id' => 1, 'role_id' => 1, 'status' => 'active']);

        $this->service->shouldReceive('delete')->with(1)->once();
        $this->service->shouldReceive('removeCustomIndexes')->with(1, $model->getAttributes())->once();
        $this->service->shouldReceive('bustVersion')->once();

        $manager = new InvalidationManager(
            service: $this->service,
            strategy: 'sync',
            versioned: true,
        );

        $manager->handle('deleted', $model);

        $this->addToAssertionCount(1);
    }

    public function test_sync_strategy_bumps_version_on_saved_event_when_versioned(): void
    {
        $model = new InvalidationTestModel(['id' => 1, 'role_id' => 1, 'status' => 'active']);

        $this->service->shouldReceive('bustVersion')->once();

        $manager = new InvalidationManager(
            service: $this->service,
            strategy: 'sync',
            versioned: true,
        );

        $manager->handle('saved', $model);

        $this->addToAssertionCount(1);
    }

    public function test_sync_strategy_skips_delete_on_saved_event(): void
    {
        $model = new InvalidationTestModel(['id' => 1, 'role_id' => 1, 'status' => 'active']);

        $this->service->shouldNotReceive('delete');
        $this->service->shouldNotReceive('removeCustomIndexes');

        $manager = new InvalidationManager(
            service: $this->service,
            strategy: 'sync',
            versioned: false,
        );

        $manager->handle('saved', $model);

        $this->addToAssertionCount(1);
    }

    public function test_async_strategy_does_not_call_service_directly(): void
    {
        $model = new InvalidationTestModel(['id' => 1, 'role_id' => 1, 'status' => 'active']);

        $this->service->shouldNotReceive('delete');
        $this->service->shouldNotReceive('removeCustomIndexes');
        $this->service->shouldNotReceive('bustVersion');

        $manager = new InvalidationManager(
            service: $this->service,
            strategy: 'async',
            versioned: false,
        );

        // Async dispatches a job — no direct service calls
        $manager->handle('deleted', $model);

        $this->addToAssertionCount(1);
    }

    public function test_throws_on_unknown_strategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown invalidation strategy');

        new InvalidationManager(
            service: $this->service,
            strategy: 'unknown',
        );
    }
}

class InvalidationTestModel extends Model
{
    protected $table = 'test_models';

    protected $guarded = [];

    public $timestamps = false;
}
