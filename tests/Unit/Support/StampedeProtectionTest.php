<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Support;

use Mockery;
use Sm_mE\RedisModelCache\Support\StampedeProtection;
use Sm_mE\RedisModelCache\Tests\TestCase;

class StampedeProtectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_acquire_lock_returns_true_when_lock_acquired(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('set')
            ->with('cache:lock', '1', ['NX', 'EX' => 10])
            ->andReturn(true);

        $result = StampedeProtection::acquireLock($redis, 'cache:lock', 10);

        $this->assertTrue($result);
    }

    public function test_acquire_lock_returns_false_when_lock_exists(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('set')
            ->with('cache:lock', '1', ['NX', 'EX' => 10])
            ->andReturn(null); // Lock already exists

        $result = StampedeProtection::acquireLock($redis, 'cache:lock', 10);

        $this->assertFalse($result);
    }

    public function test_acquire_lock_handles_ok_response(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('set')
            ->with('cache:lock', '1', ['NX', 'EX' => 10])
            ->andReturn('OK'); // Some Redis clients return 'OK'

        $result = StampedeProtection::acquireLock($redis, 'cache:lock', 10);

        $this->assertTrue($result);
    }

    public function test_release_lock_deletes_key(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('del')
            ->with('cache:lock')
            ->once()
            ->andReturn(1);

        StampedeProtection::releaseLock($redis, 'cache:lock');

        // Assertion is implicit - Mockery verifies the del was called
        $this->assertTrue(true);
    }

    public function test_wait_for_lock_returns_true_when_lock_released(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('exists')
            ->with('cache:lock')
            ->once()
            ->andReturn(false); // Lock released immediately

        $result = StampedeProtection::waitForLock($redis, 'cache:lock', 1, 50);

        $this->assertTrue($result);
    }

    public function test_wait_for_lock_returns_false_on_timeout(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('exists')
            ->with('cache:lock')
            ->andReturn(true); // Lock never released

        $result = StampedeProtection::waitForLock($redis, 'cache:lock', 1, 100);

        $this->assertFalse($result);
    }

    public function test_lock_key_generates_correct_key(): void
    {
        $lockKey = StampedeProtection::lockKey('users:hash');

        $this->assertEquals('users:hash:lock', $lockKey);
    }
}
