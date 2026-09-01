<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SMDev\RedisModelCache\Attributes\CacheIndex;
use SMDev\RedisModelCache\Attributes\CacheSorted;
use SMDev\RedisModelCache\Attributes\CacheTtl;
use SMDev\RedisModelCache\Attributes\CacheWith;
use SMDev\RedisModelCache\Concerns\HasRedisModelCache;

#[CacheTtl(7200)]
#[CacheWith(['permissions'])]
class ArrayOverridesAttributeModel extends Model
{
    use HasRedisModelCache;

    protected $table = 'array_overrides_attribute_models';

    protected $guarded = [];

    public $timestamps = false;

    #[CacheIndex]
    public string $status = '';

    #[CacheSorted]
    public int $created_at = 0;

    /**
     * @return array<string, mixed>
     */
    protected static function redisModelCacheConfig(): array
    {
        return [
            'indexes' => ['state'],
            'sorted' => [],
            'with' => [],
            'ttl' => 1800,
        ];
    }
}
