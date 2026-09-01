<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Tests\Unit\Attributes;

use ReflectionClass;
use ReflectionMethod;
use SMDev\RedisModelCache\Support\AttributeReader;
use SMDev\RedisModelCache\Tests\Fixtures\ArrayOverridesAttributeModel;
use SMDev\RedisModelCache\Tests\Fixtures\AttributeConfiguredModel;
use SMDev\RedisModelCache\Tests\TestCase;

class AttributeReaderTest extends TestCase
{
    public function test_reads_property_index_attributes(): void
    {
        $config = AttributeReader::read(AttributeConfiguredModel::class);

        $this->assertSame(['status', 'role_id'], $config['indexes']);
    }

    public function test_reads_property_sorted_attribute(): void
    {
        $config = AttributeReader::read(AttributeConfiguredModel::class);

        $this->assertSame(['created_at'], $config['sorted']);
    }

    public function test_reads_class_ttl_attribute(): void
    {
        $config = AttributeReader::read(AttributeConfiguredModel::class);

        $this->assertSame(3600, $config['ttl']);
    }

    public function test_reads_class_with_attribute(): void
    {
        $config = AttributeReader::read(AttributeConfiguredModel::class);

        $this->assertSame(['roles'], $config['with']);
    }

    public function test_attribute_reader_returns_the_same_cached_configuration(): void
    {
        $first = AttributeReader::read(AttributeConfiguredModel::class);
        $second = AttributeReader::read(AttributeConfiguredModel::class);

        $this->assertSame($first, $second);

        $reflection = new ReflectionClass(AttributeReader::class);
        $cache = $reflection->getProperty('cache')->getValue();

        $this->assertCount(1, $cache);
        $this->assertArrayHasKey(AttributeConfiguredModel::class, $cache);
    }

    public function test_array_configuration_overrides_attributes(): void
    {
        $config = $this->invokeProtectedStatic(
            'resolveRedisModelCacheConfiguration',
            ArrayOverridesAttributeModel::class,
            ArrayOverridesAttributeModel::class,
        );

        $this->assertSame(['state'], $config['indexes']);
        $this->assertSame([], $config['sorted']);
        $this->assertSame([], $config['with']);
        $this->assertSame(1800, $config['ttl']);
    }

    private function invokeProtectedStatic(string $method, string $class, mixed ...$args): mixed
    {
        $methodReflection = new ReflectionMethod($class, $method);
        $methodReflection->setAccessible(true);

        return $methodReflection->invoke(null, ...$args);
    }
}
