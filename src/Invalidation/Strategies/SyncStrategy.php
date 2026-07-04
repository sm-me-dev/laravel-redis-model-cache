<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Invalidation\Strategies;

use Sm_mE\RedisModelCache\Invalidation\Contracts\InvalidationStrategy;
use Sm_mE\RedisModelCache\Invalidation\InvalidationContext;
use Sm_mE\RedisModelCache\RedisModelService;

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
