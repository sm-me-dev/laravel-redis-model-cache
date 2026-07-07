<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Support;

use Sm_mE\RedisModelCache\Support\Configuration;
use Sm_mE\RedisModelCache\Tests\TestCase;

class ConfigurationTest extends TestCase
{
    public function test_default_values(): void
    {
        $config = new Configuration;

        $this->assertSame('cache', $config->connection);
        $this->assertSame(86400, $config->defaultTtl);
        $this->assertSame('scan', $config->scanStrategy);
        $this->assertSame(1000, $config->scanCount);
        $this->assertSame(5000, $config->hydrateBatchSize);
        $this->assertTrue($config->observabilityEnabled);
        $this->assertTrue($config->observabilityDispatchEvents);
        $this->assertTrue($config->observabilityTelescope);
        $this->assertTrue($config->observabilityPulse);
        $this->assertFalse($config->observabilityDebug);
        $this->assertFalse($config->stampedeProtectionEnabled);
        $this->assertSame(10, $config->stampedeProtectionLockTimeout);
        $this->assertSame(5, $config->stampedeProtectionWaitTimeout);
        $this->assertSame(100, $config->stampedeProtectionWaitInterval);
        $this->assertFalse($config->swrEnabled);
        $this->assertSame(300, $config->swrGracePeriod);
        $this->assertSame('default', $config->swrQueue);
        $this->assertFalse($config->compressionEnabled);
        $this->assertSame('gzip', $config->compressionAlgorithm);
        $this->assertSame(6, $config->compressionLevel);
        $this->assertSame(512, $config->compressionMinSize);
        $this->assertFalse($config->multiTenantEnabled);
        $this->assertNull($config->multiTenantResolver);
        $this->assertSame('header', $config->multiTenantStrategy);
        $this->assertSame('X-Tenant-ID', $config->multiTenantKey);
        $this->assertTrue($config->luaScriptingEnabled);
        $this->assertSame('sync', $config->invalidationStrategy);
        $this->assertFalse($config->invalidationVersioned);
        $this->assertSame('default', $config->invalidationQueue);
    }

    public function test_custom_values(): void
    {
        $config = new Configuration(
            connection: 'redis',
            defaultTtl: 3600,
            scanStrategy: 'scan',
            scanCount: 500,
            hydrateBatchSize: 1000,
            observabilityEnabled: false,
            observabilityDispatchEvents: false,
            observabilityTelescope: false,
            observabilityPulse: false,
            observabilityDebug: true,
            stampedeProtectionEnabled: true,
            stampedeProtectionLockTimeout: 30,
            stampedeProtectionWaitTimeout: 15,
            stampedeProtectionWaitInterval: 200,
            swrEnabled: true,
            swrGracePeriod: 600,
            swrQueue: 'high',
            compressionEnabled: true,
            compressionAlgorithm: 'zstd',
            compressionLevel: 3,
            compressionMinSize: 1024,
            multiTenantEnabled: true,
            multiTenantResolver: 'App\Resolvers\CustomResolver',
            multiTenantStrategy: 'subdomain',
            multiTenantKey: 'X-Org-ID',
            luaScriptingEnabled: false,
            invalidationStrategy: 'async',
            invalidationVersioned: true,
            invalidationQueue: 'cache-invalidation',
        );

        $this->assertSame('redis', $config->connection);
        $this->assertSame(3600, $config->defaultTtl);
        $this->assertSame(500, $config->scanCount);
        $this->assertFalse($config->observabilityEnabled);
        $this->assertTrue($config->stampedeProtectionEnabled);
        $this->assertSame(30, $config->stampedeProtectionLockTimeout);
        $this->assertTrue($config->swrEnabled);
        $this->assertSame(600, $config->swrGracePeriod);
        $this->assertTrue($config->compressionEnabled);
        $this->assertSame('zstd', $config->compressionAlgorithm);
        $this->assertTrue($config->multiTenantEnabled);
        $this->assertSame('App\Resolvers\CustomResolver', $config->multiTenantResolver);
        $this->assertFalse($config->luaScriptingEnabled);
        $this->assertSame('async', $config->invalidationStrategy);
        $this->assertTrue($config->invalidationVersioned);
    }

    public function test_from_config_reads_actual_config(): void
    {
        config()->set('redis-model-cache.connection', 'test-conn');
        config()->set('redis-model-cache.default_ttl', 1800);
        config()->set('redis-model-cache.compression.enabled', true);
        config()->set('redis-model-cache.compression.algorithm', 'lz4');
        config()->set('redis-model-cache.stampede_protection.enabled', true);
        config()->set('redis-model-cache.invalidation.versioned', true);

        $config = Configuration::fromConfig();

        $this->assertSame('test-conn', $config->connection);
        $this->assertSame(1800, $config->defaultTtl);
        $this->assertTrue($config->compressionEnabled);
        $this->assertSame('lz4', $config->compressionAlgorithm);
        $this->assertTrue($config->stampedeProtectionEnabled);
        $this->assertTrue($config->invalidationVersioned);
    }

    public function test_from_config_uses_defaults_when_config_missing(): void
    {
        config()->set('redis-model-cache', []);

        $config = Configuration::fromConfig();

        $this->assertSame('cache', $config->connection);
        $this->assertSame(86400, $config->defaultTtl);
        $this->assertFalse($config->compressionEnabled);
        $this->assertFalse($config->multiTenantEnabled);
        $this->assertTrue($config->luaScriptingEnabled);
        $this->assertSame('sync', $config->invalidationStrategy);
    }

    public function test_null_default_ttl(): void
    {
        config()->set('redis-model-cache.default_ttl', null);

        $config = Configuration::fromConfig();

        $this->assertNull($config->defaultTtl);
    }

    public function test_null_multi_tenant_resolver(): void
    {
        config()->set('redis-model-cache.multi_tenant.resolver', null);

        $config = Configuration::fromConfig();

        $this->assertNull($config->multiTenantResolver);
    }

    public function test_explicit_null_default_ttl(): void
    {
        $config = new Configuration(defaultTtl: null);

        $this->assertNull($config->defaultTtl);
    }
}
