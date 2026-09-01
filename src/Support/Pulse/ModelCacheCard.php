<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Support\Pulse;

use Illuminate\Contracts\View\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Optional Laravel Pulse dashboard card for Redis model cache health.
 *
 * This class is only autoloaded when the service provider detects Pulse and
 * Livewire, keeping Pulse an optional integration.
 */
#[Lazy]
final class ModelCacheCard extends Card
{
    public function render(): View
    {
        $rows = [];

        if (app()->bound('db')) {
            $rows = app('db')->table('pulse_values')
                ->where('type', 'redis_model_cache')
                ->latest('timestamp')
                ->limit(100)
                ->get();
        }

        return view('redis-model-cache::livewire.redis-model-cache-card', [
            'rows' => $rows,
            'updatedAt' => now(),
        ]);
    }
}
