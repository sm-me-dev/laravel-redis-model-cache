<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Sm_mE\RedisModelCache\Concerns\HasRedisModelCache;

class DummyModel extends Model
{
    use HasRedisModelCache;

    protected $table = 'dummy_models';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    public static function redisModelCacheConfig(): array
    {
        return [
            'indexes' => ['status'],
            'sorted' => ['created_at'],
            'custom_indexes' => ['active' => ['status' => 'active']],
            'ttl' => 3600,
        ];
    }
}

class DummySoftDeleteModel extends Model
{
    use HasRedisModelCache, SoftDeletes;

    protected $table = 'dummy_soft_delete_models';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    public static function redisModelCacheConfig(): array
    {
        return [
            'indexes' => ['status'],
            'ttl' => 3600,
        ];
    }
}
