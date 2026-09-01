<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SMDev\RedisModelCache\Attributes\CacheWith;
use SMDev\RedisModelCache\Concerns\HasRedisModelCache;

#[CacheWith(['roles', 'permissions'])]
class CacheWithLifecycleModel extends Model
{
    use HasRedisModelCache;

    protected $table = 'cache_with_lifecycle_models';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @var list<string>
     */
    public array $loadedMissingRelations = [];

    /**
     * @param  array<int, string>|string  $relations
     */
    public function loadMissing($relations): static
    {
        $list = is_array($relations) ? $relations : [$relations];

        $this->loadedMissingRelations = array_values(array_map(strval(...), $list));

        return $this;
    }
}
