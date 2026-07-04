<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support;

class ExplainResult
{
    /**
     * @param  string  $operation  The operation being explained (where, remember, rememberAll, etc.)
     * @param  array<string, mixed>  $parameters  Query parameters
     * @param  array<int, array{command: string, key: string, estimated_cardinality: int|string}>  $steps  Execution steps
     * @param  int  $totalCommands  Total Redis commands that would execute
     * @param  string  $strategy  Execution strategy (index_intersection, sorted_range, etc.)
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $parameters,
        public readonly array $steps,
        public readonly int $totalCommands,
        public readonly string $strategy,
    ) {}

    /**
     * Get a formatted string representation of the query plan.
     */
    public function toString(): string
    {
        $output = "QUERY PLAN:\n";
        $output .= "Operation: {$this->operation}\n";
        $output .= "Strategy: {$this->strategy}\n";
        $output .= 'Parameters: '.json_encode($this->parameters)."\n\n";

        foreach ($this->steps as $index => $step) {
            $stepNum = $index + 1;
            $output .= "{$stepNum}. {$step['command']} \"{$step['key']}\"\n";
            $output .= "   Estimated cardinality: {$step['estimated_cardinality']}\n";
        }

        $output .= "\nTOTAL: {$this->totalCommands} Redis commands\n";

        return $output;
    }

    /**
     * Get the query plan as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'parameters' => $this->parameters,
            'steps' => $this->steps,
            'total_commands' => $this->totalCommands,
            'strategy' => $this->strategy,
        ];
    }

    /**
     * Convert to string automatically when cast.
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
