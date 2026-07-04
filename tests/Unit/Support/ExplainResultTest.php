<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit\Support;

use Sm_mE\RedisModelCache\Support\ExplainResult;
use Sm_mE\RedisModelCache\Tests\TestCase;

class ExplainResultTest extends TestCase
{
    public function test_to_string_formats_query_plan_correctly(): void
    {
        $result = new ExplainResult(
            operation: 'where',
            parameters: ['role_id' => 1, 'active' => true],
            steps: [
                [
                    'command' => 'SINTER',
                    'key' => 'users:index:role_id:1 users:index:active:1',
                    'estimated_cardinality' => 'intersection result',
                ],
                [
                    'command' => 'Pipeline HGET × N',
                    'key' => 'users:hash',
                    'estimated_cardinality' => '10 models',
                ],
            ],
            totalCommands: 11,
            strategy: 'index_intersection'
        );

        $output = $result->toString();

        $this->assertStringContainsString('QUERY PLAN:', $output);
        $this->assertStringContainsString('Operation: where', $output);
        $this->assertStringContainsString('Strategy: index_intersection', $output);
        $this->assertStringContainsString('SINTER', $output);
        $this->assertStringContainsString('TOTAL: 11 Redis commands', $output);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $result = new ExplainResult(
            operation: 'where',
            parameters: ['role_id' => 1],
            steps: [
                [
                    'command' => 'SMEMBERS',
                    'key' => 'users:index:role_id:1',
                    'estimated_cardinality' => 100,
                ],
            ],
            totalCommands: 1,
            strategy: 'single_index_lookup'
        );

        $array = $result->toArray();

        $this->assertEquals('where', $array['operation']);
        $this->assertEquals(['role_id' => 1], $array['parameters']);
        $this->assertCount(1, $array['steps']);
        $this->assertEquals(1, $array['total_commands']);
        $this->assertEquals('single_index_lookup', $array['strategy']);
    }

    public function test_to_string_magic_method_works(): void
    {
        $result = new ExplainResult(
            operation: 'remember',
            parameters: [],
            steps: [],
            totalCommands: 0,
            strategy: 'cached'
        );

        $stringOutput = (string) $result;

        $this->assertStringContainsString('QUERY PLAN:', $stringOutput);
        $this->assertStringContainsString('Operation: remember', $stringOutput);
    }

    public function test_readonly_properties_are_accessible(): void
    {
        $result = new ExplainResult(
            operation: 'where',
            parameters: ['status' => 'active'],
            steps: [],
            totalCommands: 5,
            strategy: 'test'
        );

        $this->assertEquals('where', $result->operation);
        $this->assertEquals(['status' => 'active'], $result->parameters);
        $this->assertEquals([], $result->steps);
        $this->assertEquals(5, $result->totalCommands);
        $this->assertEquals('test', $result->strategy);
    }
}
