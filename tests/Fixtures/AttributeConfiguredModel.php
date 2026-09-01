<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SMDev\RedisModelCache\Attributes\CacheIndex;
use SMDev\RedisModelCache\Attributes\CacheSorted;
use SMDev\RedisModelCache\Attributes\CacheTtl;
use SMDev\RedisModelCache\Attributes\CacheWith;
use SMDev\RedisModelCache\Concerns\HasRedisModelCache;

#[CacheTtl(3600)]
#[CacheWith(['roles'])]
class AttributeConfiguredModel extends Model
{
    use HasRedisModelCache;

    protected $table = 'attribute_configured_models';

    protected $guarded = [];

    public $timestamps = false;

    #[CacheIndex]
    public string $status = '';

    #[CacheIndex]
    public int $role_id = 0;

    #[CacheSorted]
    public int $created_at = 0;
}
