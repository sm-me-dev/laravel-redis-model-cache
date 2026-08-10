<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Support;

/**
 * Handles Redis pipeline execution and Lua script management.
 *
 * Abstracts client-specific pipeline execution (phpredis vs Predis),
 * manages Lua script priming, and provides atomic store operations
 * via EVALSHA with graceful fallback to EVAL.
 */
final class PipelineExecutor
{
    private ?string $luaAtomicStoreSha = null;

    /**
     * @param  \Closure(mixed $client, array<int, string> $keys, array<int, string> $args): void  $luaExecutor
     */
    public function __construct(
        private readonly mixed $redis,
        private readonly Configuration $configuration,
        private readonly \Closure $luaExecutor,
    ) {}

    /**
     * Execute a pipeline in a client-agnostic way.
     *
     * phpredis puts the \Redis object itself into pipeline mode and uses exec().
     * Predis returns a dedicated Pipeline object with execute().
     *
     * @return array<int, mixed>
     */
    public function executePipeline(mixed $pipeline): array
    {
        // phpredis: pipeline() returns the same \Redis instance in pipeline mode; uses exec()
        if ($pipeline instanceof \Redis) {
            return (array) $pipeline->exec();
        }

        // Predis and test mocks: pipeline object with execute() or __call
        if (is_callable([$pipeline, 'execute'])) {
            return (array) call_user_func([$pipeline, 'execute']);
        }

        // Last resort fallback for exec()-only clients
        if (is_callable([$pipeline, 'exec'])) {
            return (array) call_user_func([$pipeline, 'exec']);
        }

        return [];
    }

    /**
     * Queue an EXPIRE command within a pipeline or execute directly.
     *
     * @param  mixed  $client
     */
    public function queueExpire($client, string $key, ?int $ttl): void
    {
        if ($ttl !== null) {
            $client->expire($key, $ttl);
        }
    }

    /**
     * Queue a Lua atomic store operation on the given client.
     *
     * @param  array<int, string>  $keys  KEYS for the Lua script
     * @param  array<int, string>  $args  ARGV for the Lua script
     */
    public function queueLuaAtomicStoreOnClient(mixed $client, array $keys, array $args): void
    {
        ($this->luaExecutor)($client, $keys, $args);
    }

    /**
     * Prime the atomic store Lua script in Redis (SCRIPT LOAD).
     * After this, EVALSHA can be used without NOSCRIPT fallback.
     */
    public function primeAtomicStoreScript(string $scriptContent, callable $loadScript): void
    {
        if ($this->luaAtomicStoreSha !== null) {
            return;
        }

        try {
            $this->luaAtomicStoreSha = $loadScript($scriptContent);
        } catch (\Exception $e) {
            $this->luaAtomicStoreSha = null;
        }
    }

    public function getLuaAtomicStoreSha(): ?string
    {
        return $this->luaAtomicStoreSha;
    }
}
