<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Sm_mE\RedisModelCache\Concerns\HasRedisModelCache;

class DummyModel extends Model
{
    use HasRedisModelCache;
}
