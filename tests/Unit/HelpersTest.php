<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Sm_mE\RedisModelCache\Tests\TestCase;

class HelpersTest extends TestCase
{
    public function test_format_bytes_returns_bytes(): void
    {
        $this->assertSame('0 B', formatBytes(0));
        $this->assertSame('1 B', formatBytes(1));
        $this->assertSame('1023 B', formatBytes(1023));
    }

    public function test_format_bytes_returns_kilobytes(): void
    {
        $this->assertSame('1 KB', formatBytes(1024));
        $this->assertSame('1.5 KB', formatBytes(1536));
        $this->assertSame('1024 KB', formatBytes(1024 * 1024 - 1));
        $this->assertSame('1023 KB', formatBytes(1024 * 1023));
    }

    public function test_format_bytes_returns_megabytes(): void
    {
        $this->assertSame('1 MB', formatBytes(1024 * 1024));
        $this->assertSame('2 MB', formatBytes(2 * 1024 * 1024));
        $this->assertSame('1024 MB', formatBytes(1024 * 1024 * 1024 - 1));
        $this->assertSame('1023 MB', formatBytes(1024 * 1024 * 1023));
    }

    public function test_format_bytes_returns_gigabytes(): void
    {
        $this->assertSame('1 GB', formatBytes(1024 * 1024 * 1024));
        $this->assertSame('1.07 GB', formatBytes((int) (1.07 * 1024 * 1024 * 1024)));
    }
}
