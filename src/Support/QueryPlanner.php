<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

use InvalidArgumentException;

/**
 * Produces a deterministic execution plan from a resolved index.
 *
 * Every plan step maps to exactly one Redis command. No guessing,
 * no fallback, no O(N) scans.
 */
class QueryPlanner
{
    /**
     * Build an execution plan for a query operation.
     *
     * @param  string  $operation  One of: 'where', 'whereIn', 'find', 'first', 'count', 'exists'
     * @param  ResolvedIndex  $index  The resolved index data
     * @param  string  $hashKey  The Redis hash key for model data
     * @param  array<string, mixed>  $options  Additional options (prefix resolver, etc.)
     *
     * @throws InvalidArgumentException If operation is not supported
     */
    public function plan(string $operation, ResolvedIndex $index, string $hashKey, array $options = []): QueryPlan
    {
        return match ($operation) {
            'find' => $this->planFind($hashKey),
            'first' => $this->planFirst($index, $hashKey),
            'count' => $this->planCount($index),
            'exists' => $this->planExists($index),
            'where', 'whereIn' => $this->planWhere($operation, $index, $hashKey),
            default => throw new InvalidArgumentException("Unsupported operation: {$operation}"),
        };
    }

    private function planFind(string $hashKey): QueryPlan
    {
        $steps = [
            [
                'command' => 'HGET',
                'key' => $hashKey,
                'description' => 'Direct hash lookup by primary key',
                'estimated_cost' => 'O(1)',
            ],
        ];

        $resolvedIndex = new ResolvedIndex(
            strategy: 'direct_hash',
            keys: [$hashKey],
            command: 'HGET',
            metadata: ['operation' => 'find'],
        );

        return new QueryPlan(
            operation: 'find',
            resolvedIndex: $resolvedIndex,
            steps: $steps,
            totalCommands: 1,
            complexity: 'O(1)',
        );
    }

    private function planFirst(ResolvedIndex $index, string $hashKey): QueryPlan
    {
        $hasIndexKeys = $this->hasConcreteKeys($index);
        $command = $index->command;

        $steps = [];

        if ($hasIndexKeys) {
            $keyList = implode(', ', $index->keys);
            $steps[] = [
                'command' => $command,
                'key' => $keyList,
                'description' => "Resolve index members via {$command} (limit 1)",
                'estimated_cost' => $command === 'SMEMBERS' ? 'O(N) but limited to 1' : 'O(N) but limited to 1',
            ];
        } else {
            $steps[] = [
                'command' => $command,
                'key' => '{prefix}:index:{field}:{value}',
                'description' => "Resolve index members via {$command} (limit 1)",
                'estimated_cost' => $command === 'SMEMBERS' ? 'O(N) but limited to 1' : 'O(N) but limited to 1',
            ];
        }

        $steps[] = [
            'command' => 'HGET',
            'key' => $hashKey,
            'description' => 'Hydrate single model from hash',
            'estimated_cost' => 'O(1)',
        ];

        return new QueryPlan(
            operation: 'first',
            resolvedIndex: $index,
            steps: $steps,
            totalCommands: 2,
            complexity: 'O(1) with index lookup',
        );
    }

    private function planCount(ResolvedIndex $index): QueryPlan
    {
        $isSingleKey = $index->isSingleKey();
        $hasConcreteKeys = $this->hasConcreteKeys($index);

        if ($isSingleKey && $hasConcreteKeys) {
            $steps = [
                [
                    'command' => 'SCARD',
                    'key' => $index->keys[0],
                    'description' => 'Cardinality lookup on single index set',
                    'estimated_cost' => 'O(1)',
                ],
            ];

            return new QueryPlan(
                operation: 'count',
                resolvedIndex: $index,
                steps: $steps,
                totalCommands: 1,
                complexity: 'O(1)',
            );
        }

        $command = $index->command;
        $steps = [];

        if ($hasConcreteKeys) {
            $keyList = implode(', ', $index->keys);
            $steps[] = [
                'command' => $command,
                'key' => $keyList,
                'description' => "Resolve members via {$command} then count",
                'estimated_cost' => 'O(N)',
            ];
        } else {
            $steps[] = [
                'command' => $command,
                'key' => '{prefix}:index:{field}:{value} ('.($index->metadata['field_count'] ?? 'N').' keys)',
                'description' => "Resolve members via {$command} then count",
                'estimated_cost' => 'O(N)',
            ];
        }

        $steps[] = [
            'command' => 'count()',
            'key' => '-',
            'description' => 'PHP count() on resolved ID array',
            'estimated_cost' => 'O(1)',
        ];

        return new QueryPlan(
            operation: 'count',
            resolvedIndex: $index,
            steps: $steps,
            totalCommands: 1,
            complexity: $isSingleKey ? 'O(1)' : 'O(N)',
        );
    }

    private function planExists(ResolvedIndex $index): QueryPlan
    {
        $isSingleKey = $index->isSingleKey();
        $hasConcreteKeys = $this->hasConcreteKeys($index);

        if ($isSingleKey && $hasConcreteKeys) {
            $steps = [
                [
                    'command' => 'EXISTS',
                    'key' => $index->keys[0],
                    'description' => 'Existence check on single index set',
                    'estimated_cost' => 'O(1)',
                ],
            ];

            return new QueryPlan(
                operation: 'exists',
                resolvedIndex: $index,
                steps: $steps,
                totalCommands: 1,
                complexity: 'O(1)',
            );
        }

        $command = $index->command;
        $steps = [];

        if ($hasConcreteKeys) {
            $keyList = implode(', ', $index->keys);
            $steps[] = [
                'command' => $command,
                'key' => $keyList,
                'description' => "Resolve members via {$command} then check non-empty",
                'estimated_cost' => 'O(N)',
            ];
        } else {
            $steps[] = [
                'command' => $command,
                'key' => '{prefix}:index:{field}:{value} ('.($index->metadata['field_count'] ?? 'N').' keys)',
                'description' => "Resolve members via {$command} then check non-empty",
                'estimated_cost' => 'O(N)',
            ];
        }

        return new QueryPlan(
            operation: 'exists',
            resolvedIndex: $index,
            steps: $steps,
            totalCommands: 1,
            complexity: $isSingleKey ? 'O(1)' : 'O(N)',
        );
    }

    private function planWhere(string $operation, ResolvedIndex $index, string $hashKey): QueryPlan
    {
        $hasConcreteKeys = $this->hasConcreteKeys($index);
        $command = $index->command;

        $steps = [];

        if ($hasConcreteKeys) {
            $keyList = implode(', ', $index->keys);
            $steps[] = [
                'command' => $command,
                'key' => $keyList,
                'description' => "Resolve model IDs via {$command}",
                'estimated_cost' => 'O(N)',
            ];
        } else {
            $steps[] = [
                'command' => $command,
                'key' => '{prefix}:index:{field}:{value} ('.($index->metadata['field_count'] ?? 'N').' keys)',
                'description' => "Resolve model IDs via {$command}",
                'estimated_cost' => 'O(N)',
            ];
        }

        $steps[] = [
            'command' => 'Pipeline HGET × N',
            'key' => $hashKey,
            'description' => 'Batch hydrate models from hash',
            'estimated_cost' => 'O(N)',
        ];

        return new QueryPlan(
            operation: $operation,
            resolvedIndex: $index,
            steps: $steps,
            totalCommands: 2,
            complexity: 'O(N)',
        );
    }

    private function hasConcreteKeys(ResolvedIndex $index): bool
    {
        return $index->keys !== [];
    }
}
