<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\RedisModelService;

class CacheManager
{
    private const string FACADE_TRAIT = 'Sm_mE\RedisModelCache\Concerns\HasRedisModelCache';

    public function __construct(
        private readonly RedisConnectionResolver $connectionResolver,
        private readonly Observability $observability,
    ) {}

    /**
     * Return a snapshot of cache metrics (hit/miss rates, latency, Redis info).
     */
    public function metrics(): CacheMetrics
    {
        $snapshot = $this->observability->snapshot();
        $redis = $this->connectionResolver->resolve();
        /** @var array<string, mixed> $info */
        $info = $redis->info();

        return new CacheMetrics(
            requests: [
                'hits' => $snapshot['hits'],
                'misses' => $snapshot['misses'],
                'total_requests' => $snapshot['total_requests'],
                'hit_rate' => $snapshot['hit_rate'],
                'miss_rate' => $snapshot['miss_rate'],
            ],
            redis: [
                'version' => (string) ($info['redis_version'] ?? 'N/A'),
                'used_memory' => (int) ($info['used_memory'] ?? 0),
                'used_memory_peak' => (int) ($info['used_memory_peak'] ?? 0),
                'uptime_seconds' => (int) ($info['uptime_in_seconds'] ?? 0),
                'connected_clients' => (int) ($info['connected_clients'] ?? 0),
                'total_keys' => $this->totalKeys($info),
                'expired_keys' => (int) ($info['expired_keys'] ?? 0),
            ],
            latency: $snapshot['latency'],
            pipelineDistribution: [
                'min' => $snapshot['pipeline_size']['min'] !== null ? (int) $snapshot['pipeline_size']['min'] : null,
                'max' => $snapshot['pipeline_size']['max'] !== null ? (int) $snapshot['pipeline_size']['max'] : null,
                'average' => $snapshot['pipeline_size']['average'],
                'median' => $snapshot['pipeline_size']['median'],
                'samples' => $snapshot['pipeline_size']['samples'],
            ],
            staleCleanup: $snapshot['stale_cleanup'],
            lockContention: $snapshot['lock_contention'],
        );
    }

    /**
     * Explain how a query would be executed against the Redis cache.
     *
     * Accepts:
     *   RedisModelCache::explain(User::class, ['role_id' => 1])
     *   RedisModelCache::explain(User::class, fn(RedisModelService $s) => $s->where(...))
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>|Closure(RedisModelService): mixed  $query
     */
    public function explain(string $modelClass, array|Closure $query): ExplainResult
    {
        $config = $this->resolveModelConfig($modelClass);
        $service = new RedisModelService(
            connectionResolver: $this->connectionResolver,
            model_class: $modelClass,
            indexes: $config['indexes'] ?? [],
            sorted: $config['sorted'] ?? [],
            custom_indexes: $config['custom_indexes'] ?? [],
            ttl: $config['ttl'] ?? null,
        );

        $service->explain();

        if (is_array($query)) {
            $result = $service->where($query);
        } else {
            $result = $query($service);
        }

        if (! $result instanceof ExplainResult) {
            throw new InvalidArgumentException(
                'The explain query did not return an ExplainResult. Ensure the query triggers explain mode.'
            );
        }

        return $result;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string, mixed>
     */
    private function resolveModelConfig(string $modelClass): array
    {
        if (
            in_array(self::FACADE_TRAIT, class_uses_recursive($modelClass), true)
            && method_exists($modelClass, 'redisModelCacheConfig')
        ) {
            return (array) $modelClass::redisModelCacheConfig();
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function totalKeys(array $info): int
    {
        foreach ($info as $key => $value) {
            if (str_starts_with((string) $key, 'db')) {
                preg_match('/keys=(\d+)/', (string) $value, $matches);

                return (int) ($matches[1] ?? 0);
            }
        }

        return 0;
    }
}
