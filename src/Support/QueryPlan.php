<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class QueryPlan
{
    /**
     * @param  string  $operation  Query operation: 'where', 'whereIn', 'find', 'first', 'count', 'exists'
     * @param  ResolvedIndex  $resolvedIndex  Resolved index data
     * @param  array<int, array{command: string, key: string, description: string, estimated_cost: string}>  $steps  Ordered execution steps
     * @param  int  $totalCommands  Total Redis commands
     * @param  string  $complexity  Big-O notation: O(1), O(log N), O(N)
     */
    public function __construct(
        public readonly string $operation,
        public readonly ResolvedIndex $resolvedIndex,
        public readonly array $steps,
        public readonly int $totalCommands,
        public readonly string $complexity,
    ) {}
}
