<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Tests\Unit\Support\Pulse;

use SMDev\RedisModelCache\Contracts\RedisConnectionResolver;
use SMDev\RedisModelCache\Events\CacheHit;
use SMDev\RedisModelCache\Support\Pulse\ModelCacheRecorder;
use SMDev\RedisModelCache\Tests\Fixtures\DummyModel;
use SMDev\RedisModelCache\Tests\TestCase;

class ModelCacheRecorderTest extends TestCase
{
    public function test_records_cache_hit_and_forwards_pulse_value(): void
    {
        $pulse = new class
        {
            /** @var list<array{type: string, value: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param  array<string, mixed>  $value
             */
            public function record(string $type, array $value): void
            {
                $this->records[] = ['type' => $type, 'value' => $value];
            }
        };
        $redis = new class
        {
            public function hlen(string $key): int
            {
                return $key === '{dummy_models}:hash' ? 42 : 0;
            }
        };
        $resolver = new class($redis) implements RedisConnectionResolver
        {
            public function __construct(private readonly object $redis) {}

            public function resolve(): mixed
            {
                return $this->redis;
            }

            public function getPrefix(): string
            {
                return '';
            }
        };

        $recorder = new ModelCacheRecorder($pulse, $resolver);
        $recorder->recordHit(new CacheHit(
            modelClass: DummyModel::class,
            query: ['status' => 'active'],
            resultCount: 1,
            executionTime: 1.5,
        ));

        $this->assertSame(1, $recorder->metrics()[DummyModel::class]['hits']);
        $this->assertSame('redis_model_cache', $pulse->records[0]['type']);
        $this->assertSame(100.0, $pulse->records[0]['value']['hit_rate']);
        $this->assertSame(42, $pulse->records[0]['value']['cached_count']);
    }
}
