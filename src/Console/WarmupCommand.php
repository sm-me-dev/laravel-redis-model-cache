<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Sm_mE\RedisModelCache\RedisModelService;

class WarmupCommand extends Command
{
    protected $signature = 'redis-model-cache:warmup
                            {model : Fully qualified model class name}
                            {--where=* : WHERE conditions in field=value format}
                            {--chunk=1000 : Number of models to process per batch}
                            {--indexes=* : Indexed fields (comma-separated)}
                            {--sorted=* : Sorted fields (comma-separated)}
                            {--ttl= : TTL in seconds (defaults to config)}';

    protected $description = 'Pre-populate Redis cache with model data';

    public function handle(): int
    {
        /** @var string $modelClass */
        $modelClass = $this->argument('model');

        // Validate model class exists
        if (! class_exists($modelClass)) {
            $this->error("Model class '{$modelClass}' not found.");

            return self::FAILURE;
        }

        // Validate model is an Eloquent model
        if (! is_subclass_of($modelClass, Model::class)) {
            $this->error("Class '{$modelClass}' must extend ".Model::class);

            return self::FAILURE;
        }

        // Parse options
        $whereConditions = $this->parseWhereConditions();
        $indexes = $this->parseArrayOption('indexes');
        $sorted = $this->parseArrayOption('sorted');
        $ttl = $this->option('ttl') ? (int) $this->option('ttl') : null;
        $chunkSize = (int) $this->option('chunk');

        // Create cache service
        $cacheService = app(RedisModelService::class, [
            'model_class' => $modelClass,
            'indexes' => $indexes,
            'sorted' => $sorted,
            'ttl' => $ttl,
        ]);

        $this->info("Warming up cache for {$modelClass}...");
        $this->newLine();

        // Build query
        /** @var Model $model */
        $model = new $modelClass;
        $query = $model->newQuery();

        // Apply where conditions
        foreach ($whereConditions as $field => $value) {
            $query->where($field, $value);
        }

        // Get total count
        $total = $query->count();

        if ($total === 0) {
            $this->warn('No models found matching criteria.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} models to cache.");
        $this->newLine();

        // Create progress bar
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        $cached = 0;

        // Process in chunks
        $query->chunk($chunkSize, function (Collection $models) use ($cacheService, $bar, &$cached) {
            // Store models in cache
            $cacheService->storeMany($models);

            $cached += $models->count();
            $bar->advance($models->count());
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("✓ Successfully cached {$cached} models.");

        // Show memory stats if available
        if ($this->option('verbose')) {
            $this->showMemoryStats($cacheService);
        }

        return self::SUCCESS;
    }

    /**
     * Parse WHERE conditions from --where options.
     *
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    protected function parseWhereConditions(): array
    {
        $conditions = [];

        /** @var array<int, string> $whereOptions */
        $whereOptions = $this->option('where');
        foreach ($whereOptions as $condition) {
            if (! str_contains($condition, '=')) {
                $this->warn("Invalid where condition: {$condition} (expected field=value)");

                continue;
            }

            [$field, $value] = explode('=', $condition, 2);
            $conditions[trim($field)] = trim($value);
        }

        return $conditions;
    }

    /**
     * Parse array option (comma-separated values).
     *
     * @return array<string>
     */
    /**
     * @return array<int, string>
     */
    protected function parseArrayOption(string $name): array
    {
        $values = [];

        /** @var array<int, string> $optionValues */
        $optionValues = $this->option($name);
        foreach ($optionValues as $value) {
            $items = array_map('trim', explode(',', $value));
            $values = array_merge($values, $items);
        }

        return array_filter($values);
    }

    /**
     * Show memory statistics for cached data.
     */
    protected function showMemoryStats(RedisModelService $cacheService): void
    {
        $this->newLine();
        $this->info('Memory Statistics:');

        try {
            $redis = $cacheService->getRedis();
            $info = $redis->info('memory');

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Used Memory', $this->formatBytes($info['used_memory'] ?? 0)],
                    ['Peak Memory', $this->formatBytes($info['used_memory_peak'] ?? 0)],
                    ['RSS Memory', $this->formatBytes($info['used_memory_rss'] ?? 0)],
                ]
            );
        } catch (\Exception $e) {
            $this->warn('Could not retrieve memory stats: '.$e->getMessage());
        }
    }

    /**
     * Format bytes to human-readable format.
     */
    protected function formatBytes(int $bytes): string
    {
        return \formatBytes($bytes);
    }
}
