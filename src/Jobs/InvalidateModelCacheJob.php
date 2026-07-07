<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sm_mE\RedisModelCache\Invalidation\InvalidationContext;
use Sm_mE\RedisModelCache\Invalidation\Strategies\SyncStrategy;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Support\Configuration;

class InvalidateModelCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public InvalidationContext $context,
    ) {}

    public function handle(): void
    {
        $modelClass = $this->context->modelClass;
        $config = method_exists($modelClass, 'redisModelCacheConfig')
            ? $modelClass::redisModelCacheConfig()
            : [];

        $service = app(RedisModelService::class, [
            'model_class' => $modelClass,
            'indexes' => $config['indexes'] ?? [],
            'sorted' => $config['sorted'] ?? [],
            'custom_indexes' => $config['custom_indexes'] ?? [],
            'ttl' => $config['ttl'] ?? null,
            'connection' => $config['connection'] ?? null,
        ]);

        $versioned = Configuration::fromConfig()->invalidationVersioned;
        $strategy = new SyncStrategy($service, $versioned);
        $strategy->invalidate($this->context);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 10, 30];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(5);
    }
}
