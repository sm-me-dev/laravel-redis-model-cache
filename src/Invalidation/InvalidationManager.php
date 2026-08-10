<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Invalidation;

use Illuminate\Database\Eloquent\Model;
use SmMe\RedisModelCache\Events\ModelCacheInvalidated;
use SmMe\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use SmMe\RedisModelCache\Invalidation\Strategies\AsyncStrategy;
use SmMe\RedisModelCache\Invalidation\Strategies\SyncStrategy;
use SmMe\RedisModelCache\RedisModelService;

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
