<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SMDev\RedisModelCache\Contracts\RedisConnectionResolver;
use SMDev\RedisModelCache\Support\StampedeProtection;

/**
 * Safely releases a distributed SWR lock after revalidation completes.
 */
final class ReleaseSWRLockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $lockKey,
        private readonly string $lockValue,
    ) {}

    public function handle(): void
    {
        $redis = app(RedisConnectionResolver::class)->resolve();
        StampedeProtection::releaseLockCas($redis, $this->lockKey, $this->lockValue);
    }
}
