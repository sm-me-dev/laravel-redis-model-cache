<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Sm_mE\RedisModelCache\Tests\TestCase as BaseTestCase;

abstract class IntegrationTestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->redisAvailable()) {
            $this->markTestSkipped(
                'Redis is not available. Integration tests require a running Redis server (default: 127.0.0.1:6379).'
            );
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\Orchestra\Testbench\workbench_path('database/migrations'));
    }

    protected function tearDown(): void
    {
        $this->flushTestKeys();

        parent::tearDown();
    }

    private function redisAvailable(): bool
    {
        try {
            $redis = app('redis')->connection('cache');
            $redis->ping();

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function flushTestKeys(): void
    {
        try {
            $redis = app('redis')->connection('cache');

            $patterns = [
                '{dummy_models}:*',
                '{cache}:*',
                '{tenant:*}:*',
            ];

            foreach ($patterns as $pattern) {
                try {
                    $iterator = null;
                    do {
                        $keys = $redis->scan($iterator, $pattern, 100);
                        if ($keys) {
                            $redis->del(...$keys);
                        }
                    } while ($iterator !== null && $iterator !== false && $iterator !== 0);
                } catch (\Exception $e) {
                }
            }
        } catch (\Exception $e) {
        }

        usleep(200000);
    }
}
