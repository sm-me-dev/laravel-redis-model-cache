<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\RedisHelperService;
use Sm_mE\RedisModelCache\Tests\TestCase;

class RedisHelperServiceTest extends TestCase
{
    private RedisConnectionResolver|MockInterface $connectionResolver;

    private MockInterface $redis;

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

    public function test_remember_set_caches_result_with_serialization(): void
    {
        $service = new RedisHelperService($this->connectionResolver, 3600);

        $this->redis->shouldReceive('hExists')->with('users', '1')->andReturn(false);
        $this->redis->shouldReceive('hset')->with('users', '1', '{"name":"John"}')->andReturn(1);
        $this->redis->shouldReceive('ttl')->with('users')->andReturn(-1); // No TTL set yet
        $this->redis->shouldReceive('expire')->with('users', 3600)->andReturn(true);

        $result = $service->rememberSet('users', '1', fn () => ['name' => 'John']);

        $this->assertEquals(['name' => 'John'], $result);
    }

    public function test_remember_set_returns_cached_value_without_callback(): void
    {
        $service = new RedisHelperService($this->connectionResolver, 3600);

        $this->redis->shouldReceive('hExists')->with('users', '1')->andReturn(true);
        $this->redis->shouldReceive('hget')->with('users', '1')->andReturn('{"name":"John"}');

        $result = $service->rememberSet('users', '1', fn () => ['name' => 'Jane']);

        $this->assertEquals(['name' => 'John'], $result);
    }

    public function test_remember_set_refresh_true_bypasses_cache(): void
    {
        $service = new RedisHelperService($this->connectionResolver, 3600);

        $this->redis->shouldReceive('hExists')->with('users', '1')->andReturn(true);
        $this->redis->shouldReceive('hset')->with('users', '1', '{"name":"Jane"}')->andReturn(1);
        $this->redis->shouldReceive('ttl')->with('users')->andReturn(-1);
        $this->redis->shouldReceive('expire')->with('users', 3600)->andReturn(true);

        $result = $service->rememberSet('users', '1', fn () => ['name' => 'Jane'], true);

        $this->assertEquals(['name' => 'Jane'], $result);
    }

    public function test_remember_set_without_serialization(): void
    {
        $service = new RedisHelperService($this->connectionResolver, 3600);

        $this->redis->shouldReceive('hExists')->with('users', '1')->andReturn(false);
        $this->redis->shouldReceive('hset')->with('users', '1', 'plain string')->andReturn(1);
        $this->redis->shouldReceive('ttl')->with('users')->andReturn(-1);
        $this->redis->shouldReceive('expire')->with('users', 3600)->andReturn(true);

        $result = $service->rememberSet('users', '1', fn () => 'plain string', false, false);

        $this->assertEquals('plain string', $result);
    }

    public function test_get_set_returns_deserialized_hash(): void
    {
        $service = new RedisHelperService($this->connectionResolver, 3600);

        $this->redis->shouldReceive('hGetAll')->with('users')->andReturn([
            '1' => '{"name":"John"}',
            '2' => '{"name":"Jane"}',
        ]);

        $result = $service->getSet('users');

        $this->assertEquals([
            '1' => ['name' => 'John'],
            '2' => ['name' => 'Jane'],
        ], $result);
    }

    public function test_get_set_returns_empty_array_when_no_data(): void
    {
        $service = new RedisHelperService($this->connectionResolver, 3600);

        $this->redis->shouldReceive('hGetAll')->with('users')->andReturn([]);

        $result = $service->getSet('users');

        $this->assertEquals([], $result);
    }
}
