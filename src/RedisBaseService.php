<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache;

use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;

class RedisBaseService
{
    /** @deprecated Will become protected in v2.0. Use a service-specific facade instead of accessing $redis directly. */
    public mixed $redis;

    protected ?int $ttl = null;

    protected string $redisPrefix;

    /** @var array<string, string|null> Loaded Lua script SHAs keyed by script name */
    protected array $luaScriptShas = [];

    /**
     * Atomic store-model Lua script.
     *
     * All Redis key names are passed as KEYS so that client-side prefix
     * (e.g. Laravel's "laravel_database_") is applied correctly.
     *
     * KEYS[1]                        = hashKey
     * KEYS[2..1+N]                   = stale SREM index keys
     * KEYS[2+N..1+N+M]               = new SADD index keys
     * KEYS[2+N+M..1+N+M+P]           = stale ZREM sorted keys
     * KEYS[2+N+M+P..1+N+M+P+Q]       = new ZADD sorted keys
     *
     * ARGV[1] = modelId
     * ARGV[2] = serialized payload
     * ARGV[3] = ttl (0 = no expiry)
     * ARGV[4] = "N M P Q" (space-separated count of keys in each category)
     * ARGV[5] = "score1,score2,..." (comma-separated scores for ZADD entries)
     */
    protected const LUA_ATOMIC_STORE = <<<'LUA'
local hashKey = KEYS[1]
local modelId = ARGV[1]
local payload = ARGV[2]
local ttl = tonumber(ARGV[3])

local counts = {}
local idx = 1
for token in string.gmatch(ARGV[4], "%S+") do
    counts[idx] = tonumber(token)
    idx = idx + 1
end
local numStaleSrem = counts[1] or 0
local numNewSadd   = counts[2] or 0
local numStaleZrem = counts[3] or 0
local numNewZadd   = counts[4] or 0

local scores = {}
if numNewZadd > 0 then
    idx = 1
    for token in string.gmatch(ARGV[5], "[^,]+") do
        scores[idx] = tonumber(token)
        idx = idx + 1
    end
end

redis.call('HSET', hashKey, modelId, payload)

local ki = 2

for i = 1, numStaleSrem do
    redis.call('SREM', KEYS[ki], modelId)
    ki = ki + 1
end

for i = 1, numNewSadd do
    redis.call('SADD', KEYS[ki], modelId)
    if ttl > 0 then
        redis.call('EXPIRE', KEYS[ki], ttl)
    end
    ki = ki + 1
end

for i = 1, numStaleZrem do
    redis.call('ZREM', KEYS[ki], modelId)
    ki = ki + 1
end

for i = 1, numNewZadd do
    redis.call('ZADD', KEYS[ki], scores[i], modelId)
    if ttl > 0 then
        redis.call('EXPIRE', KEYS[ki], ttl)
    end
    ki = ki + 1
end

if ttl > 0 then
    redis.call('EXPIRE', hashKey, ttl)
end

return 1
LUA;

    /**
     * Atomic lock-release Lua script (compare-and-swap).
     *
     * KEYS[1] = lockKey
     * ARGV[1] = expected value
     *
     * Only deletes the lock if the stored value matches ARGV[1],
     * preventing accidental release of another process' lock.
     */
    protected const LUA_LOCK_CAS = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    public function __construct(
        protected RedisConnectionResolver $connectionResolver,
        ?int $ttl = null
    ) {
        $this->ttl = $ttl ?? (int) config('redis-model-cache.default_ttl', 86400);
        $this->redis = $connectionResolver->resolve();
        $this->redisPrefix = $connectionResolver->getPrefix();
    }

    /**
     * Get the underlying Redis client instance.
     */
    public function getRedis(): mixed
    {
        return $this->redis;
    }

    protected function applyTTL(string $key): void
    {
        if (! $this->ttl) {
            return;
        }

        $ttl = $this->redis->ttl($key);

        if ($ttl === -1) {
            $this->redis->expire($key, $this->ttl);
        }
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function applyTTLToKeys(array $keys): void
    {
        if (! $this->ttl) {
            return;
        }

        foreach ($keys as $key) {
            if ($key !== '') {
                $this->applyTTL($key);
            }
        }
    }

    protected function serializeResult(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }

        $json = (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->compress($json);
    }

    protected function deserializeResult(string $data): mixed
    {
        $json = $this->decompress($data);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Compress data if compression is enabled and payload exceeds minimum threshold.
     */
    protected function compress(string $data): string
    {
        if (! config('redis-model-cache.compression.enabled', false)) {
            return $data;
        }

        $minSize = (int) config('redis-model-cache.compression.min_size', 512);

        if (strlen($data) < $minSize) {
            return $data;
        }

        $algorithm = config('redis-model-cache.compression.algorithm', 'gzip');
        $level = (int) config('redis-model-cache.compression.level', 6);

        return match ($algorithm) {
            'gzip' => $this->compressGzip($data, $level),
            'zstd' => $this->compressZstd($data, $level),
            'lz4' => $this->compressLz4($data),
            default => $data,
        };
    }

    /**
     * Decompress data if it was compressed.
     * Auto-detects compression format.
     */
    protected function decompress(string $data): string
    {
        if (! config('redis-model-cache.compression.enabled', false)) {
            return $data;
        }

        // Auto-detect compression format by magic bytes
        if (str_starts_with($data, "\x1f\x8b")) {
            // Gzip magic bytes
            return $this->decompressGzip($data);
        } elseif (str_starts_with($data, "\x28\xb5\x2f\xfd")) {
            // Zstd magic bytes
            return $this->decompressZstd($data);
        } elseif (str_starts_with($data, "\x04\x22\x4d\x18")) {
            // LZ4 magic bytes
            return $this->decompressLz4($data);
        }

        // Not compressed, return as-is
        return $data;
    }

    /**
     * Compress using gzip (widely supported).
     */
    protected function compressGzip(string $data, int $level): string
    {
        $compressed = gzencode($data, $level);

        if ($compressed === false) {
            return $data;
        }

        return $compressed;
    }

    /**
     * Decompress gzip data.
     */
    protected function decompressGzip(string $data): string
    {
        $decompressed = gzdecode($data);

        if ($decompressed === false) {
            return $data;
        }

        return $decompressed;
    }

    /**
     * Compress using zstd (best compression ratio, PHP 7.3+).
     */
    protected function compressZstd(string $data, int $level): string
    {
        if (! function_exists('zstd_compress')) {
            return $data;
        }

        $compressed = zstd_compress($data, $level);

        if ($compressed === false) {
            return $data;
        }

        return $compressed;
    }

    /**
     * Decompress zstd data.
     */
    protected function decompressZstd(string $data): string
    {
        if (! function_exists('zstd_uncompress')) {
            return $data;
        }

        $decompressed = zstd_uncompress($data);

        if ($decompressed === false) {
            return $data;
        }

        return $decompressed;
    }

    /**
     * Compress using lz4 (fastest, requires ext-lz4).
     */
    protected function compressLz4(string $data): string
    {
        if (! function_exists('lz4_compress_frame')) {
            return $data;
        }

        $compressed = lz4_compress_frame($data);

        if ($compressed === false) {
            return $data;
        }

        return $compressed;
    }

    /**
     * Decompress lz4 data.
     */
    protected function decompressLz4(string $data): string
    {
        if (! function_exists('lz4_uncompress_frame')) {
            return $data;
        }

        $decompressed = lz4_uncompress_frame($data);

        if ($decompressed === false) {
            return $data;
        }

        return $decompressed;
    }

    /**
     * Check whether Lua scripting is enabled in config and available on the
     * underlying Redis connection.
     */
    protected function luaEnabled(): bool
    {
        return (bool) config('redis-model-cache.lua_scripting.enabled', true);
    }

    /**
     * Execute a Lua script using EVALSHA with automatic EVAL fallback.
     *
     * When the SHA is known (non-null), EVALSHA is attempted first. If
     * Redis responds with NOSCRIPT, the script is loaded via EVAL and the
     * SHA is cached via the reference parameter for the next call.
     *
     * @param  string  $script  The Lua source code
     * @param  array<int, string>  $keys  Redis keys to pass (KEYS table)
     * @param  array<int, string>  $args  Non-key arguments (ARGV table)
     * @param  string|null  $sha  Cached SHA-1; updated via reference after first EVAL
     * @return mixed Redis response
     */
    protected function executeLua(string $script, array $keys, array $args, ?string &$sha = null): mixed
    {
        $numKeys = count($keys);
        $allArgs = array_merge($keys, $args);

        // EVALSHA fast path
        if ($sha !== null) {
            $result = $this->evalSha($sha, $allArgs, $numKeys);
            if ($result !== false) {
                return $result;
            }
        }

        // EVAL (also loads the script on the server)
        $result = $this->evalRaw($script, $allArgs, $numKeys);

        if ($sha === null) {
            $sha = sha1($script);
        }

        return $result;
    }

    /**
     * Load a Lua script via SCRIPT LOAD and return its SHA-1.
     */
    protected function loadScript(string $script): string
    {
        if ($this->redis instanceof \Redis) {
            return (string) $this->redis->script('load', $script);
        }

        return (string) $this->redis->script('load', $script);
    }

    /**
     * Try Lua first; fall back to the pipeline callable on failure or
     * when Lua is disabled in config.
     *
     * @param  string  $script  Lua source
     * @param  array<int, string>  $keys  KEYS for the script
     * @param  array<int, string>  $args  ARGV for the script
     * @param  string|null  $sha  Cached SHA reference
     * @param  callable(): mixed  $fallback  Pipeline/commands fallback
     */
    protected function evaluateLuaOrPipeline(
        string $script,
        array $keys,
        array $args,
        ?string &$sha,
        callable $fallback
    ): mixed {
        if (! $this->luaEnabled()) {
            return $fallback();
        }

        try {
            return $this->executeLua($script, $keys, $args, $sha);
        } catch (\Exception $e) {
            // Lua unavailable — fall back to the caller's pipeline/command path
            return $fallback();
        }
    }

    /**
     * Dispatch EVALSHA with a client-agnostic interface.
     *
     * @param  string  $sha  SHA-1 of the cached script
     * @param  array<int, string>  $args  keys + arguments concatenated
     * @param  int  $numKeys  how many of $args are key names
     */
    protected function evalSha(string $sha, array $args, int $numKeys): mixed
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis->evalSha($sha, $args, $numKeys);
        }

        // Predis
        return $this->redis->evalSha($sha, $numKeys, ...$args);
    }

    /**
     * Dispatch EVAL with a client-agnostic interface.
     *
     * @param  string  $script  Lua source
     * @param  array<int, string>  $args  keys + arguments concatenated
     * @param  int  $numKeys  how many of $args are key names
     */
    protected function evalRaw(string $script, array $args, int $numKeys): mixed
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis->eval($script, $args, $numKeys);
        }

        // Predis
        return $this->redis->eval($script, $numKeys, ...$args);
    }
}
