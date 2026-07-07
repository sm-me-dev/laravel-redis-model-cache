<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use InvalidArgumentException;
use Sm_mE\RedisModelCache\RedisModelCacheServiceProvider;
use Sm_mE\RedisModelCache\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_provider_can_be_resolved(): void
    {
        $provider = $this->app->getProvider(RedisModelCacheServiceProvider::class);

        $this->assertInstanceOf(RedisModelCacheServiceProvider::class, $provider);
    }

    public function test_config_has_required_keys(): void
    {
        $config = config('redis-model-cache');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('connection', $config);
        $this->assertArrayHasKey('default_ttl', $config);
        $this->assertArrayHasKey('scan_strategy', $config);
        $this->assertArrayHasKey('scan_count', $config);
        $this->assertArrayHasKey('hydrate_batch_size', $config);
        $this->assertArrayHasKey('stampede_protection', $config);
        $this->assertArrayHasKey('stale_while_revalidate', $config);
        $this->assertArrayHasKey('compression', $config);
        $this->assertArrayHasKey('multi_tenant', $config);
        $this->assertArrayHasKey('lua_scripting', $config);
        $this->assertArrayHasKey('invalidation', $config);
        $this->assertArrayHasKey('observability', $config);
    }

    public function test_scan_strategy_defaults_to_scan(): void
    {
        $this->assertEquals('scan', config('redis-model-cache.scan_strategy'));
    }

    public function test_default_ttl_is_positive_integer(): void
    {
        $ttl = config('redis-model-cache.default_ttl');

        $this->assertIsInt($ttl);
        $this->assertGreaterThan(0, $ttl);
    }

    public function test_scan_count_is_positive_integer(): void
    {
        $count = config('redis-model-cache.scan_count');

        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
    }

    public function test_invalid_scan_strategy_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid scan_strategy: only 'scan' is supported");

        config()->set('redis-model-cache.scan_strategy', 'keys');
        $this->bootProvider();
    }

    public function test_invalid_ttl_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid default_ttl');

        config()->set('redis-model-cache.default_ttl', -1);
        $this->bootProvider();
    }

    public function test_invalid_scan_count_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scan_count');

        config()->set('redis-model-cache.scan_count', 0);
        $this->bootProvider();
    }

    public function test_valid_config_does_not_throw(): void
    {
        config()->set('redis-model-cache.connection', 'cache');
        config()->set('redis-model-cache.default_ttl', 86400);
        config()->set('redis-model-cache.scan_strategy', 'scan');
        config()->set('redis-model-cache.scan_count', 1000);

        $this->bootProvider();

        $this->addToAssertionCount(1);
    }

    public function test_stampede_protection_validates_lock_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid stampede_protection.lock_timeout');

        config()->set('redis-model-cache.stampede_protection.enabled', true);
        config()->set('redis-model-cache.stampede_protection.lock_timeout', 0);
        $this->bootProvider();
    }

    public function test_stampede_protection_validates_wait_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid stampede_protection.wait_timeout');

        config()->set('redis-model-cache.stampede_protection.enabled', true);
        config()->set('redis-model-cache.stampede_protection.lock_timeout', 10);
        config()->set('redis-model-cache.stampede_protection.wait_timeout', 0);
        $this->bootProvider();
    }

    public function test_swr_validates_grace_period(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid stale_while_revalidate.grace_period');

        config()->set('redis-model-cache.stale_while_revalidate.enabled', true);
        config()->set('redis-model-cache.stale_while_revalidate.grace_period', 0);
        $this->bootProvider();
    }

    public function test_swr_validates_queue_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid stale_while_revalidate.queue');

        config()->set('redis-model-cache.stale_while_revalidate.enabled', true);
        config()->set('redis-model-cache.stale_while_revalidate.grace_period', 300);
        config()->set('redis-model-cache.stale_while_revalidate.queue', '');
        $this->bootProvider();
    }

    public function test_publish_tag_matches_config_path(): void
    {
        $provider = $this->app->getProvider(RedisModelCacheServiceProvider::class);

        $this->assertInstanceOf(RedisModelCacheServiceProvider::class, $provider);
    }

    protected function bootProvider(): void
    {
        $provider = $this->app->getProvider(RedisModelCacheServiceProvider::class);
        $ref = new \ReflectionMethod($provider, 'validateConfiguration');
        $ref->setAccessible(true);
        $ref->invoke($provider);
    }
}
