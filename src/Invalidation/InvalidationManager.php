<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Invalidation;

use Illuminate\Database\Eloquent\Model;
use SmmE\RedisModelCache\Events\ModelCacheInvalidated;
use SmmE\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use SmmE\RedisModelCache\Invalidation\Strategies\AsyncStrategy;
use SmmE\RedisModelCache\Invalidation\Strategies\SyncStrategy;
use SmmE\RedisModelCache\RedisModelService;

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
