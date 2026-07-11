<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\Events\CacheOperationFailed;
use Sm_mE\RedisModelCache\Events\RedisConnectionFailed;
use Sm_mE\RedisModelCache\RedisBaseService;
use Sm_mE\RedisModelCache\Tests\TestCase;

class RedisFailureStrategyTest extends TestCase
{
    private MockInterface $redis;

    private RedisConnectionResolver|MockInterface $connectionResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock('Illuminate\Redis\Connections\Connection');
        $this->connectionResolver = Mockery::mock(RedisConnectionResolver::class);
        $this->connectionResolver->shouldReceive('resolve')->andReturn($this->redis);
        $this->connectionResolver->shouldReceive('getPrefix')->andReturn('');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_config_has_redis_failure_defaults(): void
    {
        $config = config('redis-model-cache');

        $this->assertArrayHasKey('redis_failure', $config);
        $this->assertSame('exception', $config['redis_failure']['strategy']);
        $this->assertTrue($config['redis_failure']['log']);
        $this->assertSame('stack', $config['redis_failure']['log_channel']);
        $this->assertNull($config['redis_failure']['fallback_callback']);
    }

    public function test_exception_strategy_rethrows_redis_exception(): void
    {
        config([
            'redis-model-cache.redis_failure.strategy' => 'exception',
            'redis-model-cache.lua_scripting.enabled' => false,
        ]);

        $service = new FailureTestService($this->connectionResolver);

        $this->redis->shouldReceive('hget')
            ->with('{test}:hash', '1')
            ->andThrow(new \RedisException('Connection refused'));

        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('Connection refused');

        $service->find(1);
    }

    public function test_log_strategy_does_not_throw(): void
    {
        config([
            'redis-model-cache.redis_failure.strategy' => 'log',
            'redis-model-cache.redis_failure.log' => true,
            'redis-model-cache.redis_failure.log_channel' => 'stack',
            'redis-model-cache.lua_scripting.enabled' => false,
        ]);

        Log::shouldReceive('channel')
            ->with('stack')
            ->andReturnSelf()
            ->once();

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(fn (string $msg) => str_contains($msg, 'Redis operation')),
                Mockery::type('array')
            );

        $service = new FailureTestService($this->connectionResolver);

        $this->redis->shouldReceive('hget')
            ->with('{test}:hash', '1')
            ->andThrow(new \RedisException('Connection refused'));

        $result = $service->find(1);

        $this->assertNull($result);
    }

    public function test_log_strategy_dispatches_event(): void
    {
        config([
            'redis-model-cache.redis_failure.strategy' => 'log',
            'redis-model-cache.redis_failure.log' => false,
            'redis-model-cache.lua_scripting.enabled' => false,
        ]);

        Event::fake();

        $service = new FailureTestService($this->connectionResolver);

        $this->redis->shouldReceive('hget')
            ->with('{test}:hash', '1')
            ->andThrow(new \RedisException('Connection refused'));

        $service->find(1);

        Event::assertDispatched(RedisConnectionFailed::class, function (RedisConnectionFailed $event) {
            return $event->operation === 'find' && str_contains($event->message, 'Connection refused');
        });
    }

    public function test_fallback_strategy_invokes_callback(): void
    {
        $fallback = function (\RedisException $e, string $operation) {
            return 'fallback_value';
        };

        config([
            'redis-model-cache.redis_failure.strategy' => 'fallback',
            'redis-model-cache.redis_failure.fallback_callback' => $fallback,
            'redis-model-cache.lua_scripting.enabled' => false,
        ]);

        $service = new FailureTestService($this->connectionResolver);

        $this->redis->shouldReceive('hget')
            ->with('{test}:hash', '1')
            ->andThrow(new \RedisException('Connection refused'));

        $result = $service->find(1);

        $this->assertSame('fallback_value', $result);
    }

    public function test_fallback_strategy_dispatches_event(): void
    {
        $fallback = function (\RedisException $e, string $operation) {
            return 'fallback_value';
        };

        config([
            'redis-model-cache.redis_failure.strategy' => 'fallback',
            'redis-model-cache.redis_failure.fallback_callback' => $fallback,
            'redis-model-cache.redis_failure.log' => false,
            'redis-model-cache.lua_scripting.enabled' => false,
        ]);

        Event::fake();

        $service = new FailureTestService($this->connectionResolver);

        $this->redis->shouldReceive('hget')
            ->with('{test}:hash', '1')
            ->andThrow(new \RedisException('Connection refused'));

        $service->find(1);

        Event::assertDispatched(CacheOperationFailed::class, function (CacheOperationFailed $event) {
            return $event->operation === 'find'
                && $event->fallbackResult === 'fallback_value'
                && $event->strategy === 'fallback';
        });
    }

    public function test_log_strategy_suppresses_exception_for_ttl_operations(): void
    {
        config([
            'redis-model-cache.redis_failure.strategy' => 'log',
            'redis-model-cache.redis_failure.log' => false,
            'redis-model-cache.lua_scripting.enabled' => false,
        ]);

        $service = new FailureTestService($this->connectionResolver);

        $this->redis->shouldReceive('hget')
            ->with('{test}:hash', '1')
            ->andReturn(json_encode(
                ['id' => 1, 'name' => 'test'],
                JSON_THROW_ON_ERROR
            ));

        $this->redis->shouldReceive('hset')->with('{test}:meta', 'cached_at', Mockery::type('string'))->andReturn(1);
        $this->redis->shouldReceive('expire')->with('{test}:meta', 3600)->andReturn(true);
        $this->redis->shouldReceive('ttl')->with('{test}:hash')->andThrow(new \RedisException('Connection refused'));

        $result = $service->find(1);

        $this->assertNotNull($result);
        $this->assertIsArray($result);
        $this->assertSame(1, $result['id']);
    }

    public function test_fallback_strategy_with_null_callback_falls_back_to_log(): void
    {
        config([
            'redis-model-cache.redis_failure.strategy' => 'fallback',
            'redis-model-cache.redis_failure.fallback_callback' => null,
            'redis-model-cache.redis_failure.log' => true,
            'redis-model-cache.redis_failure.log_channel' => 'stack',
            'redis-model-cache.lua_scripting.enabled' => false,
        ]);

        Log::shouldReceive('channel')
            ->with('stack')
            ->andReturnSelf()
            ->once();

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(fn (string $msg) => str_contains($msg, 'no fallback')),
                Mockery::type('array')
            );

        $service = new FailureTestService($this->connectionResolver);

        $this->redis->shouldReceive('hget')
            ->with('{test}:hash', '1')
            ->andThrow(new \RedisException('Connection refused'));

        $result = $service->find(1);

        $this->assertNull($result);
    }
}

class FailureTestService extends RedisBaseService
{
    public function __construct(
        RedisConnectionResolver $connectionResolver,
    ) {
        parent::__construct($connectionResolver, ttl: 3600);
    }

    public function find(int|string $id): mixed
    {
        return $this->redisFailureHandler(function () use ($id): mixed {
            $data = $this->redis->hget('{test}:hash', (string) $id);

            if ($data === false || $data === null) {
                return null;
            }

            $this->applyTTL('{test}:hash');

            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        }, 'find');
    }
}
