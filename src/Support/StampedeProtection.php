<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class StampedeProtection
{
    /**
     * Lua script for atomic lock release (compare-and-swap).
     *
     * KEYS[1] = lockKey
     * ARGV[1] = expected value
     *
     * Only deletes the lock if the stored value matches ARGV[1],
     * preventing accidental release of another process' lock.
     */
    public const LUA_LOCK_CAS = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    /**
     * Acquire a lock for cache rebuild to prevent stampede.
     *
     * @param  mixed  $redis  Redis connection instance
     * @param  string  $lockKey  The lock key
     * @param  int  $timeout  Lock timeout in seconds
     * @return bool True if lock acquired, false otherwise
     */
    public static function acquireLock($redis, string $lockKey, int $timeout): bool
    {
        $result = $redis->set($lockKey, '1', ['NX', 'EX' => $timeout]);

        return $result === true || $result === 'OK';
    }

    /**
     * Acquire a lock and return a unique identifier for CAS release.
     *
     * The returned value must be passed to releaseLockCas() to safely
     * release only this process' lock.
     *
     * @param  mixed  $redis  Redis connection instance
     * @param  string  $lockKey  The lock key
     * @param  int  $timeout  Lock timeout in seconds
     * @return string|null The lock identifier if acquired, null otherwise
     */
    public static function acquireLockWithValue($redis, string $lockKey, int $timeout): ?string
    {
        $value = bin2hex(random_bytes(16));

        $result = $redis->set($lockKey, $value, ['NX', 'EX' => $timeout]);

        return ($result === true || $result === 'OK') ? $value : null;
    }

    /**
     * Release a stampede protection lock.
     *
     * @param  mixed  $redis  Redis connection instance
     * @param  string  $lockKey  The lock key
     */
    public static function releaseLock($redis, string $lockKey): void
    {
        $redis->del($lockKey);
    }

    /**
     * Release a lock using Lua compare-and-swap.
     *
     * Only deletes the lock if the stored value matches the expected
     * value, preventing accidental release of another process' lock
     * (e.g. when our lock timed out and another process acquired it).
     *
     * @param  mixed  $redis  Redis connection instance
     * @param  string  $lockKey  The lock key
     * @param  string  $expectedValue  The value stored when the lock was acquired
     * @param  string|null  $sha  Cached SHA reference for EVALSHA
     * @return bool True if lock was released, false if value didn't match
     */
    public static function releaseLockCas($redis, string $lockKey, string $expectedValue, ?string &$sha = null): bool
    {
        $numKeys = 1;
        $allArgs = [$lockKey, $expectedValue];

        // EVALSHA fast path
        if ($sha !== null) {
            try {
                $result = self::executeEvalSha($redis, $sha, $allArgs, $numKeys);
                if ($result !== false) {
                    return (int) $result === 1;
                }
            } catch (\Exception $e) {
                // Log exception if logging is available
            }
        }

        // EVAL
        try {
            $result = self::executeEval($redis, self::LUA_LOCK_CAS, $allArgs, $numKeys);

            if ($sha === null) {
                $sha = sha1(self::LUA_LOCK_CAS);
            }

            return (int) $result === 1;
        } catch (\Exception $e) {
            // Lua unavailable — fall back to simple DEL
            $redis->del($lockKey);

            return true;
        }
    }

    /**
     * Wait for lock to be released or timeout.
     *
     * Uses exponential backoff with random jitter to desynchronize
     * concurrent waiters and avoid thundering herd on lock release.
     * Fails fast once the deadline is exceeded.
     *
     * @param  mixed  $redis  Redis connection instance
     * @param  string  $lockKey  The lock key
     * @param  int  $waitTimeout  Maximum time to wait in seconds
     * @param  int  $waitInterval  Base sleep interval in milliseconds (doubles each attempt)
     * @return bool True if lock was released, false if timed out
     */
    public static function waitForLock($redis, string $lockKey, int $waitTimeout, int $waitInterval = 100): bool
    {
        $deadline = microtime(true) + $waitTimeout;
        $base = max(10, $waitInterval);
        $maxSleep = 1000;
        $attempt = 0;

        // Initial random jitter to de-synchronise first poll across concurrent workers
        usleep(random_int(0, min($base, $maxSleep)) * 100);

        while (microtime(true) < $deadline) {
            if (! $redis->exists($lockKey)) {
                return true;
            }

            $sleepMs = min($maxSleep, $base * (2 ** $attempt));
            $jitter = random_int(0, (int) ($sleepMs / 2));
            usleep(($sleepMs + $jitter) * 1000);

            $attempt++;
        }

        return false;
    }

    /**
     * Generate a stampede lock key for a given cache key.
     */
    public static function lockKey(string $cacheKey): string
    {
        return "{$cacheKey}:lock";
    }

    /**
     * Client-agnostic EVALSHA dispatch.
     *
     * @param  mixed  $redis
     * @param  array<int, string>  $args
     */
    private static function executeEvalSha($redis, string $sha, array $args, int $numKeys): mixed
    {
        if ($redis instanceof \Redis) {
            return $redis->evalSha($sha, $args, $numKeys);
        }

        return $redis->evalSha($sha, $numKeys, ...$args);
    }

    /**
     * Client-agnostic EVAL dispatch.
     *
     * @param  mixed  $redis
     * @param  array<int, string>  $args
     */
    private static function executeEval($redis, string $script, array $args, int $numKeys): mixed
    {
        if ($redis instanceof \Redis) {
            return $redis->eval($script, $args, $numKeys);
        }

        return $redis->eval($script, $numKeys, ...$args);
    }
}
