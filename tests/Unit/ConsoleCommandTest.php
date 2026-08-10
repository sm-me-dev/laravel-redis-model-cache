<?php

declare(strict_types=1);

namespace SmMe\RedisModelCache\Tests\Unit;

use SmMe\RedisModelCache\Console\DebugCommand;
use SmMe\RedisModelCache\Console\MonitorCacheCommand;
use SmMe\RedisModelCache\Console\WarmupCommand;
use SmMe\RedisModelCache\Tests\TestCase;

class ConsoleCommandTest extends TestCase
{
    public function test_debug_command_uses_correct_signature(): void
    {
        $command = $this->app->make(DebugCommand::class);
        $this->assertStringContainsString('redis-model-cache:debug', $command->getName());
    }

    public function test_debug_command_has_legacy_alias(): void
    {
        $command = $this->app->make(DebugCommand::class);
        $this->assertContains('redis-cache:debug', $command->getAliases());
    }

    public function test_monitor_cache_command_uses_correct_signature(): void
    {
        $command = $this->app->make(MonitorCacheCommand::class);
        $this->assertStringContainsString('redis-model-cache:monitor-cache', $command->getName());
    }

    public function test_monitor_cache_command_has_legacy_alias(): void
    {
        $command = $this->app->make(MonitorCacheCommand::class);
        $this->assertContains('redis:monitor-cache', $command->getAliases());
    }

    public function test_warmup_command_uses_correct_signature(): void
    {
        $command = $this->app->make(WarmupCommand::class);
        $this->assertStringContainsString('redis-model-cache:warmup', $command->getName());
    }

    public function test_warmup_command_has_no_legacy_aliases(): void
    {
        $command = $this->app->make(WarmupCommand::class);
        $this->assertEmpty($command->getAliases());
    }
}
