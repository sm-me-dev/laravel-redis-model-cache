<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Tests\Unit\Testing;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\ExpectationFailedException;
use SMDev\RedisModelCache\Contracts\ModelCacheService;
use SMDev\RedisModelCache\RedisModelService;
use SMDev\RedisModelCache\Testing\RedisModelCacheFake;
use SMDev\RedisModelCache\Tests\Fixtures\DummyModel;
use SMDev\RedisModelCache\Tests\TestCase;

class RedisModelCacheFakeTest extends TestCase
{
    public function test_fake_replaces_both_container_bindings(): void
    {
        $fake = RedisModelCacheFake::fake();

        $this->assertSame($fake, app(ModelCacheService::class));
        $this->assertSame($fake, app(RedisModelService::class));
    }

    public function test_store_and_assert_stored(): void
    {
        $fake = RedisModelCacheFake::fake();
        $model = new DummyModel(['id' => 1, 'status' => 'active']);

        $fake->store($model);

        $fake->assertStored(DummyModel::class, 1);
        $this->assertSame(1, $fake->callCount(DummyModel::class, 'store'));
    }

    public function test_assert_not_stored_fails_when_model_is_stored(): void
    {
        $fake = RedisModelCacheFake::fake();
        $fake->store(new DummyModel(['id' => 1]));

        $this->expectException(ExpectationFailedException::class);

        $fake->assertNotStored(DummyModel::class, 1);
    }

    public function test_find_returns_the_stored_model(): void
    {
        $fake = RedisModelCacheFake::fake();
        $model = new DummyModel(['id' => 7, 'status' => 'active']);
        $fake->store($model);

        $this->assertSame($model, $fake->find(7));
    }

    public function test_where_filters_models_by_attribute(): void
    {
        $fake = RedisModelCacheFake::fake();
        $active = new DummyModel(['id' => 1, 'status' => 'active']);
        $inactive = new DummyModel(['id' => 2, 'status' => 'inactive']);
        $fake->storeMany(collect([$active, $inactive]));

        $matches = $fake->where(['status' => 'active']);

        $this->assertCount(1, $matches);
        $this->assertSame($active, $matches->first());
    }

    public function test_delete_and_assert_deleted(): void
    {
        $fake = RedisModelCacheFake::fake();
        $fake->store(new DummyModel(['id' => 1]));

        $fake->delete(1);

        $fake->assertDeleted(DummyModel::class, 1);
        $this->assertNull($fake->find(1));
    }

    public function test_assert_nothing_stored_passes_for_fresh_fake_and_fails_after_store(): void
    {
        $fake = RedisModelCacheFake::fake();
        $fake->assertNothingStored();

        $fake->store(new DummyModel(['id' => 1]));

        $this->expectException(ExpectationFailedException::class);

        $fake->assertNothingStored();
    }

    public function test_stored_for_returns_only_models_for_the_requested_class(): void
    {
        $fake = RedisModelCacheFake::fake();
        $dummy = new DummyModel(['id' => 1]);
        $other = new OtherFakeModel(['id' => 2]);
        $fake->store($dummy);
        $fake->store($other);

        $stored = $fake->storedFor(DummyModel::class);

        $this->assertCount(1, $stored);
        $this->assertSame($dummy, $stored->first());
        $this->assertCount(0, $fake->storedFor(OtherFakeModel::class)->filter(
            fn (OtherFakeModel $model): bool => $model->getKey() !== 2
        ));
    }

    public function test_unsupported_interface_methods_throw_helpful_exception(): void
    {
        $fake = RedisModelCacheFake::fake();

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Fake does not implement remember. Stub it in your test.');

        $fake->remember(fn () => null);
    }
}

class OtherFakeModel extends Model
{
    protected $table = 'other_fake_models';

    protected $guarded = [];

    public $timestamps = false;
}
