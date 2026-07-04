<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Events;

use Sm_mE\RedisModelCache\Events\CacheHit;
use Sm_mE\RedisModelCache\Events\CacheMiss;
use Sm_mE\RedisModelCache\Events\QueryExecuted;
use Sm_mE\RedisModelCache\Tests\TestCase;

class EventsTest extends TestCase
{
    public function test_cache_hit_event_stores_data_correctly(): void
    {
        $event = new CacheHit(
            modelClass: 'App\\Models\\User',
            query: ['role_id' => 1],
            resultCount: 42,
            executionTime: 12.5
        );

        $this->assertEquals('App\\Models\\User', $event->modelClass);
        $this->assertEquals(['role_id' => 1], $event->query);
        $this->assertEquals(42, $event->resultCount);
        $this->assertEquals(12.5, $event->executionTime);
    }

    public function test_cache_miss_event_stores_data_correctly(): void
    {
        $event = new CacheMiss(
            modelClass: 'App\\Models\\User',
            query: ['status' => 'active'],
            stampedeProtectionUsed: true,
            executionTime: 150.3
        );

        $this->assertEquals('App\\Models\\User', $event->modelClass);
        $this->assertEquals(['status' => 'active'], $event->query);
        $this->assertTrue($event->stampedeProtectionUsed);
        $this->assertEquals(150.3, $event->executionTime);
    }

    public function test_cache_miss_event_with_no_stampede_protection(): void
    {
        $event = new CacheMiss(
            modelClass: 'App\\Models\\Post',
            query: [],
            stampedeProtectionUsed: false,
            executionTime: 89.2
        );

        $this->assertFalse($event->stampedeProtectionUsed);
    }

    public function test_query_executed_event_stores_data_correctly(): void
    {
        $event = new QueryExecuted(
            modelClass: 'App\\Models\\Order',
            operation: 'where',
            parameters: ['customer_id' => 123],
            commandCount: 5,
            executionTime: 8.7,
            resultCount: 10
        );

        $this->assertEquals('App\\Models\\Order', $event->modelClass);
        $this->assertEquals('where', $event->operation);
        $this->assertEquals(['customer_id' => 123], $event->parameters);
        $this->assertEquals(5, $event->commandCount);
        $this->assertEquals(8.7, $event->executionTime);
        $this->assertEquals(10, $event->resultCount);
    }

    public function test_events_are_readonly(): void
    {
        $event = new CacheHit(
            modelClass: 'Test',
            query: [],
            resultCount: 1,
            executionTime: 1.0
        );

        // Readonly properties should not be modifiable
        $this->expectException(\Error::class);
        $event->modelClass = 'Modified'; // @phpstan-ignore-line
    }
}
