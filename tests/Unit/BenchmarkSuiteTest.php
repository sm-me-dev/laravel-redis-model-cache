<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Illuminate\Support\Facades\Redis;
use Sm_mE\RedisModelCache\Tests\TestCase;

class BenchmarkSuiteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not loaded');
        }

        // Flush test Redis data
        Redis::connection('cache')->flushdb();
    }

    public function test_throughput_benchmark_executes_without_error_at_small_scale(): void
    {
        $output = shell_exec('php '.__DIR__.'/../../benchmarks/throughput_benchmark.php --scale=100 2>&1');

        $this->assertNotNull($output, 'Benchmark script should execute without error');
        $this->assertStringContainsString('Throughput Benchmark Complete', (string) $output);
    }

    public function test_latency_benchmark_executes_without_error(): void
    {
        $output = shell_exec('php '.__DIR__.'/../../benchmarks/latency_benchmark.php --operations=100 2>&1');

        $this->assertNotNull($output, 'Benchmark script should execute without error');
        $this->assertStringContainsString('Latency Benchmark Complete', (string) $output);
    }

    public function test_memory_benchmark_executes_without_error(): void
    {
        $output = shell_exec('php '.__DIR__.'/../../benchmarks/memory_benchmark.php 2>&1');

        $this->assertNotNull($output, 'Benchmark script should execute without error');
        $this->assertStringContainsString('Memory Benchmark Complete', (string) $output);
    }

    public function test_incremental_update_benchmark_executes_without_error(): void
    {
        $output = shell_exec('php '.__DIR__.'/../../benchmarks/incremental_update_benchmark.php 2>&1');

        $this->assertNotNull($output, 'Benchmark script should execute without error');
        $this->assertStringContainsString('Benchmark complete', (string) $output);
    }

    public function test_benchmark_reports_positive_throughput_values(): void
    {
        $output = shell_exec('php '.__DIR__.'/../../benchmarks/throughput_benchmark.php --scale=100 2>&1');

        $this->assertNotNull($output);
        $this->assertStringContainsString('models/sec', (string) $output);
        $this->assertStringContainsString('queries/sec', (string) $output);
    }

    public function test_latency_benchmark_reports_percentiles(): void
    {
        $output = shell_exec('php '.__DIR__.'/../../benchmarks/latency_benchmark.php --operations=100 2>&1');

        $this->assertNotNull($output);
        $this->assertStringContainsString('P50', (string) $output);
        $this->assertStringContainsString('P95', (string) $output);
        $this->assertStringContainsString('P99', (string) $output);
    }

    public function test_memory_benchmark_reports_memory_usage(): void
    {
        $output = shell_exec('php '.__DIR__.'/../../benchmarks/memory_benchmark.php 2>&1');

        $this->assertNotNull($output);
        $this->assertStringContainsString('Redis memory', (string) $output);
    }

    public function test_all_benchmark_scripts_are_executable(): void
    {
        $scripts = [
            __DIR__.'/../../benchmarks/throughput_benchmark.php',
            __DIR__.'/../../benchmarks/latency_benchmark.php',
            __DIR__.'/../../benchmarks/memory_benchmark.php',
            __DIR__.'/../../benchmarks/incremental_update_benchmark.php',
        ];

        foreach ($scripts as $script) {
            $this->assertFileExists($script, "Benchmark script {$script} should exist");
            $this->assertTrue(is_executable($script), "Benchmark script {$script} should be executable");
        }
    }
}
