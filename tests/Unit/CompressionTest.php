<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\RedisBaseService;
use Sm_mE\RedisModelCache\Tests\TestCase;

class CompressionTest extends TestCase
{
    private MockInterface $redis;

    private MockInterface $connectionResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock('Illuminate\Redis\Connections\Connection');
        $this->connectionResolver = Mockery::mock(RedisConnectionResolver::class);
        $this->connectionResolver->shouldReceive('resolve')->andReturn($this->redis);
        $this->connectionResolver->shouldReceive('getPrefix')->andReturn('');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_compress_and_decompress_gzip(): void
    {
        $data = 'This is test data that should be compressed using gzip algorithm.';

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'gzip',
            'redis-model-cache.compression.level' => 6,
        ]);

        $service = new TestableRedisBaseService($this->connectionResolver);

        $compressed = $service->callCompress($data);

        $this->assertNotSame($data, $compressed);
        $this->assertTrue(str_starts_with($compressed, "\x1f\x8b"));

        $decompressed = $service->callDecompress($compressed);

        $this->assertSame($data, $decompressed);
    }

    public function test_compress_and_decompress_zstd(): void
    {
        if (! function_exists('zstd_compress')) {
            $this->markTestSkipped('zstd extension not available');
        }

        $data = 'This is test data that should be compressed using zstd algorithm.';

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'zstd',
            'redis-model-cache.compression.level' => 6,
        ]);

        $service = new TestableRedisBaseService($this->connectionResolver);

        $compressed = $service->callCompress($data);

        $this->assertNotSame($data, $compressed);
        $this->assertTrue(str_starts_with($compressed, "\x28\xb5\x2f\xfd"));

        $decompressed = $service->callDecompress($compressed);

        $this->assertSame($data, $decompressed);
    }

    public function test_compress_and_decompress_lz4(): void
    {
        if (! function_exists('lz4_compress')) {
            $this->markTestSkipped('lz4 extension not available');
        }

        $data = 'This is test data that should be compressed using lz4 algorithm.';

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'lz4',
        ]);

        $service = new TestableRedisBaseService($this->connectionResolver);

        $compressed = $service->callCompress($data);

        $this->assertNotSame($data, $compressed);
        $this->assertTrue(str_starts_with($compressed, "\x04\x22\x4d\x18"));

        $decompressed = $service->callDecompress($compressed);

        $this->assertSame($data, $decompressed);
    }

    public function test_compress_uses_algorithm_from_config(): void
    {
        $data = 'Test data for algorithm selection';

        // Test gzip
        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'gzip',
            'redis-model-cache.compression.level' => 6,
        ]);

        $service1 = new TestableRedisBaseService($this->connectionResolver);
        $gzResult = $service1->callCompress($data);

        $this->assertTrue(str_starts_with($gzResult, "\x1f\x8b"), 'Expected gzip magic bytes');
    }

    public function test_compresses_large_data(): void
    {
        $data = str_repeat('This is a large string that should be compressed effectively to test compression with significant data volume.', 100);

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'gzip',
            'redis-model-cache.compression.level' => 9,
        ]);

        $service = new TestableRedisBaseService($this->connectionResolver);

        $compressed = $service->callCompress($data);
        $decompressed = $service->callDecompress($compressed);

        $this->assertSame($data, $decompressed);
        $this->assertLessThan(strlen($data), strlen($compressed));
    }

    public function test_does_not_compress_when_disabled(): void
    {
        $data = 'This data should not be compressed when compression is disabled.';

        config([
            'redis-model-cache.compression.enabled' => false,
        ]);

        $service = new TestableRedisBaseService($this->connectionResolver);

        $result = $service->callCompress($data);
        $decompressed = $service->callDecompress($result);

        $this->assertSame($data, $result);
        $this->assertSame($data, $decompressed);
    }

    public function test_deserialize_auto_detects_compression_format(): void
    {
        $data = 'Auto-detection test string';

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'gzip',
        ]);

        $service = new TestableRedisBaseService($this->connectionResolver);

        $compressed = $service->callCompress($data);

        $decompressed = $service->callDecompress($compressed);

        $this->assertSame($data, $decompressed);
    }

    public function test_compression_with_empty_string(): void
    {
        $data = '';

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'gzip',
        ]);

        $service = new TestableRedisBaseService($this->connectionResolver);

        $result = $service->callCompress($data);
        $decompressed = $service->callDecompress($result);

        $this->assertSame($data, $decompressed);
    }

    public function test_compression_with_json_data(): void
    {
        $data = json_encode([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'roles' => ['admin', 'user'],
            'metadata' => [
                'created_at' => '2024-01-01',
                'updated_at' => '2024-01-02',
            ],
        ]);

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'gzip',
            'redis-model-cache.compression.level' => 6,
        ]);

        $service = new TestableRedisBaseService($this->connectionResolver);

        $compressed = $service->callCompress($data);
        $decompressed = $service->callDecompress($compressed);

        $this->assertSame($data, $decompressed);

        $decoded = json_decode($decompressed, true);
        $this->assertIsArray($decoded);
        $this->assertEquals(1, $decoded['id']);
        $this->assertEquals('Test User', $decoded['name']);
    }

    public function test_compression_level_affects_ratio(): void
    {
        $data = str_repeat('The quick brown fox jumps over the lazy dog.', 50);

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'gzip',
            'redis-model-cache.compression.level' => 1,
        ]);

        $service1 = new TestableRedisBaseService($this->connectionResolver);
        $compressed1 = $service1->callCompress($data);

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'gzip',
            'redis-model-cache.compression.level' => 9,
        ]);

        $service2 = new TestableRedisBaseService($this->connectionResolver);
        $compressed9 = $service2->callCompress($data);

        $this->assertLessThan(strlen($compressed1), strlen($compressed9));

        $decompressed1 = $service1->callDecompress($compressed1);
        $decompressed9 = $service2->callDecompress($compressed9);

        $this->assertSame($data, $decompressed1);
        $this->assertSame($data, $decompressed9);
    }

    public function test_regular_data_not_wrapped_as_json_when_already_string(): void
    {
        $data = 'Plain string data that should not be wrapped in JSON first';

        config([
            'redis-model-cache.compression.enabled' => true,
            'redis-model-cache.compression.algorithm' => 'gzip',
        ]);

        $service = new TestableRedisBaseService($this->connectionResolver);

        $result = $service->callCompress($data);
        $decompressed = $service->callDecompress($result);

        $this->assertSame($data, $decompressed);
    }
}

class TestableRedisBaseService extends RedisBaseService
{
    public function callCompress(string $data): string
    {
        return $this->compress($data);
    }

    public function callDecompress(string $data): string
    {
        return $this->decompress($data);
    }
}
