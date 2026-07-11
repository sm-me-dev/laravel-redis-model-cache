<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Support\Configuration;

/**
 * Background job for revalidating stale cache entries.
 *
 * This job is dispatched when Stale-While-Revalidate (SWR) pattern is enabled
 * and cache is stale but within the grace period. It executes the cache callback
 * in the background to refresh the cache while the application continues serving
 * stale data.
 */
class RevalidateCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Wrapped callable stored as SerializableClosure for queue serialization. */
    protected SerializableClosure $callback;

    /**
     * Create a new job instance.
     *
     * @param  string  $modelClass  Fully qualified model class name
     * @param  Closure  $callback  Cache population callback
     * @param  array<string, mixed>  $where  Query conditions for cache key generation
     * @param  array<string>  $indexes  Regular indexes
     * @param  array<string>  $sorted  Sorted indexes
     * @param  array<string, array<int, string>>  $customIndexes  Custom indexes configuration
     * @param  int|null  $ttl  Time-to-live in seconds
     * @param  string|null  $redisConnection  Redis connection name
     */
    public function __construct(
        protected string $modelClass,
        Closure $callback,
        protected array $where = [],
        protected array $indexes = [],
        protected array $sorted = [],
        protected array $customIndexes = [],
        protected ?int $ttl = null,
        protected ?string $redisConnection = null,
        protected ?float $revalidationTime = null,
    ) {
        // Wrap closure for serialization support (required for queued jobs)
        try {
            $this->callback = new SerializableClosure($callback);
            serialize($this->callback);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                "Unable to serialize SWR callback closure. Ensure the closure does not capture any non-serializable objects or resources. Error: {$e->getMessage()}",
                0,
                $e
            );
        }

        // Use the configured SWR queue
        $this->onQueue(Configuration::fromConfig()->swrQueue);
    }

    /**
     * Execute the cache revalidation job.
     *
     * This method instantiates a RedisModelService and calls rememberAll()
     * to rebuild the cache. The atomic store Lua script compares the
     * revalidation timestamp against _last_invalidated_at in the meta
     * hash. If an invalidation occurred after the job was dispatched
     * (meaning the model was saved again), the Lua script skips the
     * write — the next cache miss will rebuild fresh data.
     *
     * This prevents stale-while-revalidate race conditions where a newer
     * model save triggers invalidation, but a stale background job
     * overwrites the fresh data.
     */
    public function handle(): void
    {
        try {
            $revalidationStartedAt = $this->revalidationTime ?? microtime(true);

            $service = app(RedisModelService::class, [
                'model_class' => $this->modelClass,
                'indexes' => $this->indexes,
                'sorted' => $this->sorted,
                'custom_indexes' => $this->customIndexes,
                'ttl' => $this->ttl,
                'connection' => $this->redisConnection,
            ]);

            // Revalidate cache by calling rememberAll with refresh:true to force rebuild.
            // The atomic Lua script inside storeMany() checks _last_invalidated_at
            // against revalidationStartedAt and skips stale writes atomically.
            // After storeMany(), storeCacheMetadata() updates the cached_at timestamp.
            $service->rememberAll(
                callback: $this->callback->getClosure(),
                where: $this->where,
                refresh: true,
                stampede: false,
                swr: false,
                revalidationTime: $revalidationStartedAt,
            );

            // Note: The SWR lock acquired by the dispatcher is NOT released here.
            // It was acquired without a value token, so we cannot safely perform
            // CAS release. The lock will expire via its TTL (swrGracePeriod).
            // Safety: rely on TTL rather than unsafe deletion.

            Log::debug('Cache revalidation completed', [
                'model' => $this->modelClass,
                'where' => $this->where,
            ]);
        } catch (\Throwable $e) {
            Log::error('Cache revalidation failed', [
                'model' => $this->modelClass,
                'where' => $this->where,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to mark job as failed (but don't auto-retry)
            throw $e;
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * Don't retry revalidation jobs - if they fail once, they'll likely
     * fail again, and we want to avoid queue buildup.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return []; // No retries
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        // Don't retry after initial attempt
        return now()->addSeconds(1);
    }
}
