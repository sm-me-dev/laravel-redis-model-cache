<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use SMDev\RedisModelCache\Attributes\CacheIndex;
use SMDev\RedisModelCache\Attributes\CacheSorted;
use SMDev\RedisModelCache\Attributes\CacheTtl;
use SMDev\RedisModelCache\Attributes\CacheWith;

/**
 * Reads and caches Redis model-cache configuration declared with PHP attributes.
 */
final class AttributeReader
{
    /**
     * @var array<class-string<Model>, array{
     *     indexes: list<string>,
     *     sorted: list<string>,
     *     with: list<string>,
     *     ttl: int|null
     * }>
     */
    private static array $cache = [];

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{
     *     indexes: list<string>,
     *     sorted: list<string>,
     *     with: list<string>,
     *     ttl: int|null
     * }
     */
    public static function read(string $modelClass): array
    {
        if (isset(self::$cache[$modelClass])) {
            return self::$cache[$modelClass];
        }

        $reflection = new ReflectionClass($modelClass);
        $indexes = [];
        $sorted = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(CacheIndex::class) !== []) {
                $indexes[] = $property->getName();
            }

            if ($property->getAttributes(CacheSorted::class) !== []) {
                $sorted[] = $property->getName();
            }
        }

        $with = [];
        $cacheWithAttributes = $reflection->getAttributes(CacheWith::class);
        if ($cacheWithAttributes !== []) {
            /** @var CacheWith $cacheWith */
            $cacheWith = $cacheWithAttributes[0]->newInstance();
            $with = array_values($cacheWith->relations);
        }

        $ttl = null;
        $cacheTtlAttributes = $reflection->getAttributes(CacheTtl::class);
        if ($cacheTtlAttributes !== []) {
            /** @var CacheTtl $cacheTtl */
            $cacheTtl = $cacheTtlAttributes[0]->newInstance();
            $ttl = $cacheTtl->seconds;
        }

        return self::$cache[$modelClass] = [
            'indexes' => $indexes,
            'sorted' => $sorted,
            'with' => $with,
            'ttl' => $ttl,
        ];
    }
}
