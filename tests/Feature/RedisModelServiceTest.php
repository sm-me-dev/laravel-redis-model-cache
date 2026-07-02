<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\TestCase;

class RedisModelServiceTest extends TestCase
{
    public function test_service_can_be_resolved_from_container(): void
    {
        $service = app(RedisModelService::class, [
            'model_class' => FeatureTestModel::class,
            'indexes' => ['name'],
        ]);

        $this->assertInstanceOf(RedisModelService::class, $service);
    }
}

class FeatureTestModel extends Model
{
    protected $table = 'feature_test_models';

    protected $guarded = [];

    public $timestamps = false;
}
