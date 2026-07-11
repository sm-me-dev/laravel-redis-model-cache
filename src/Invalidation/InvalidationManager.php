<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Invalidation;

use Illuminate\Database\Eloquent\Model;
use Sm_mE\RedisModelCache\Events\ModelCacheInvalidated;
use Sm_mE\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use Sm_mE\RedisModelCache\Invalidation\Strategies\AsyncStrategy;
use Sm_mE\RedisModelCache\Invalidation\Strategies\SyncStrategy;
use Sm_mE\RedisModelCache\RedisModelService;

final class InvalidationManager
{
    private readonly InvalidationStrategy $strategy;

    public function __construct(
        private readonly RedisModelService $service,
        string $strategy = 'sync',
        bool $versioned = false,
        string $queue = 'default',
    ) {
        $this->strategy = match ($strategy) {
            'sync' => new SyncStrategy($service, $versioned),
            'async' => new AsyncStrategy($queue),
            default => throw new \InvalidArgumentException("Unknown invalidation strategy: {$strategy}"),
        };
    }

    public function handle(string $event, Model $model): void
    {
        $context = new InvalidationContext(
            modelClass: $model::class,
            modelId: $model->getKey(),
            event: $event,
            attributes: $model->getAttributes(),
            original: $model->getOriginal(),
            timestamp: microtime(true),
        );

        $this->service->touchInvalidationTimestamp();

        $this->strategy->invalidate($context);

        ModelCacheInvalidated::dispatch(
            modelClass: $model::class,
            modelId: $model->getKey(),
            event: $event,
            timestamp: microtime(true),
        );
    }
}
