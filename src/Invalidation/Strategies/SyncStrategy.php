<?php

declare(strict_types=1);

namespace SmmE\RedisModelCache\Invalidation\Strategies;

use SmmE\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use SmmE\RedisModelCache\Invalidation\InvalidationContext;
use SmmE\RedisModelCache\RedisModelService;

final class SyncStrategy implements InvalidationStrategy
{
    public function __construct(
        private readonly RedisModelService $service,
        private readonly bool $versioned,
    ) {}

    public function invalidate(InvalidationContext $context): void
    {
        match ($context->event) {
            'deleted' => $this->handleDeleted($context),
            'saved', 'updated' => $this->handleSave($context),
            default => null,
        };
    }

    private function handleDeleted(InvalidationContext $context): void
    {
        $this->service->delete($context->modelId);
        $this->service->removeCustomIndexes($context->modelId, $context->attributes);

        if ($this->versioned) {
            $this->service->bustVersion();
        }
    }

    private function handleSave(InvalidationContext $context): void
    {
        if (! $this->versioned) {
            return;
        }

        $this->service->bustVersion();
    }
}
