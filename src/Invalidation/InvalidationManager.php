<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Invalidation;

use Illuminate\Database\Eloquent\Model;
use SMDev\RedisModelCache\Events\ModelCacheInvalidated;
use SMDev\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use SMDev\RedisModelCache\Invalidation\Strategies\AsyncStrategy;
use SMDev\RedisModelCache\Invalidation\Strategies\SyncStrategy;
use SMDev\RedisModelCache\RedisModelService;

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
            $model::class,
            $model->getKey(),
            $event,
            microtime(true),
        );
    }
}
