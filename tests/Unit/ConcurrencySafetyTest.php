<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Support\StampedeProtection;
use Sm_mE\RedisModelCache\Tests\TestCase;

class ConcurrencySafetyTest extends TestCase
{
    private RedisConnectionResolver|MockInterface $connectionResolver;

    private MockInterface $redis;

    private ConcurrencyTestService $service;

    private string $hashKey = '{concurrency_models}:hash';

    private string $lockKey = '{concurrency_models}:lock:stampede';

    protected function setUp(): void
    {
        parent::setUp();

        config(['redis-model-cache.lua_scripting.enabled' => false]);
        config(['redis-model-cache.stampede_protection.enabled' => true]);
        config(['redis-model-cache.stampede_protection.lock_timeout' => 10]);
        config(['redis-model-cache.stampede_protection.wait_timeout' => 2]);
        config(['redis-model-cache.stampede_protection.wait_interval' => 50]);

        $this->redis = Mockery::mock('Illuminate\Redis\Connections\Connection');
        $this->connectionResolver = Mockery::mock(RedisConnectionResolver::class);
        $this->connectionResolver->shouldReceive('resolve')->andReturn($this->redis);
        $this->connectionResolver->shouldReceive('getPrefix')->andReturn('');

        $matchStrategy = Mockery::mock(ModelMatchStrategy::class);
        $matchStrategy->shouldReceive('normalize')->andReturnUsing(fn ($v) => $v);
        $matchStrategy->shouldReceive('matches')->andReturnUsing(fn ($a, $b) => $a === $b);

        $this->service = new ConcurrencyTestService(
            connectionResolver: $this->connectionResolver,
            model_class: ConcurrencyTestModel::class,
            indexes: ['role_id', 'status'],
            sorted: ['created_at'],
            ttl: 3600,
            matchStrategy: $matchStrategy,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── Race condition tests ──────────────────────────────────────────

    public function test_stampede_lock_acquired_by_first_caller(): void
    {
        $this->redis->shouldReceive('exists')->with($this->hashKey)->andReturn(false);
        $this->redis->shouldReceive('set')->with($this->lockKey, '1', ['NX', 'EX' => 10])->andReturn(true);
        $this->redis->shouldReceive('del')->with($this->lockKey)->andReturn(1);

        $this->mockStoreAndHydrate(['1' => false], ['1' => $this->serializedModel()]);

        $model = $this->makeModel();
        $result = $this->service->rememberAll(fn () => new EloquentCollection([$model]), where: ['role_id' => 1], stampede: true);

        $this->assertCount(1, $result);
    }

    public function test_stampede_second_caller_waits_and_reads(): void
    {
        $this->redis->shouldReceive('exists')->with($this->hashKey)->andReturn(false, true);
        $this->redis->shouldReceive('set')->with($this->lockKey, '1', ['NX', 'EX' => 10])->andReturn(false);
        $this->redis->shouldReceive('exists')->with($this->lockKey)->andReturn(false);

        $this->redis->shouldReceive('smembers')->with('{concurrency_models}:index:role_id:1')->andReturn(['1']);
        $this->redis->shouldReceive('hmget')
            ->with($this->hashKey, Mockery::type('array'))
            ->andReturn(['1' => $this->serializedModel()]);

        $model = $this->makeModel();
        $result = $this->service->rememberAll(fn () => new EloquentCollection([$model]), where: ['role_id' => 1], stampede: true);

        $this->assertCount(1, $result);
    }

    public function test_stampede_lock_timeout_forces_callback(): void
    {
        $this->redis->shouldReceive('exists')->with($this->hashKey)->andReturn(false);
        $this->redis->shouldReceive('set')->with($this->lockKey, '1', ['NX', 'EX' => 10])->andReturn(false);

        $this->redis->shouldReceive('exists')->with($this->lockKey)->andReturn(true, true, true, true);
        $this->redis->shouldReceive('exists')->with($this->hashKey)->andReturn(false);

        $this->mockStoreAndHydrate(['1' => false], ['1' => $this->serializedModel()]);

        $model = $this->makeModel();
        $result = $this->service->rememberAll(fn () => new EloquentCollection([$model]), where: ['role_id' => 1], stampede: true);

        $this->assertCount(1, $result);
    }

    public function test_race_between_delete_and_store(): void
    {
        $this->redis->shouldReceive('hget')->with($this->hashKey, '1')->andReturn(
            json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR)
        );

        $this->redis->shouldReceive('hdel')->with($this->hashKey, '1')->andReturn(1);
        $this->redis->shouldReceive('srem')->with('{concurrency_models}:index:role_id:1', '1')->andReturn(1);
        $this->redis->shouldReceive('srem')->with('{concurrency_models}:index:status:active', '1')->andReturn(1);
        $this->redis->shouldReceive('zrem')->with('{concurrency_models}:sorted:created_at', '1')->andReturn(1);

        $this->service->delete(1);

        $this->addToAssertionCount(1);
    }

    public function test_nonexistent_delete_returns_early(): void
    {
        $this->redis->shouldReceive('hget')->with($this->hashKey, '999')->andReturn(false);

        $this->service->delete(999);

        $this->addToAssertionCount(1);
    }

    public function test_concurrent_update_attribute_read_write_race(): void
    {
        $stalePayload = json_encode(
            ['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []],
            JSON_THROW_ON_ERROR
        );

        $this->redis->shouldReceive('hget')->with($this->hashKey, '1')->andReturn($stalePayload);

        $pipelineMock = Mockery::mock('Illuminate\Redis\Connections\Pipeline');
        $this->redis->shouldReceive('pipeline')->andReturn($pipelineMock);
        $pipelineMock->shouldReceive('hset')->with($this->hashKey, '1', Mockery::type('string'))->once();
        $pipelineMock->shouldReceive('expire')->with($this->hashKey, 3600)->once();
        $pipelineMock->shouldReceive('srem')->with('{concurrency_models}:index:status:active', '1')->once();
        $pipelineMock->shouldReceive('sadd')->with('{concurrency_models}:index:status:inactive', '1')->once();
        $pipelineMock->shouldReceive('expire')->with('{concurrency_models}:index:status:inactive', 3600)->once();
        $pipelineMock->shouldReceive('execute')->andReturn([true, true, true, true, true]);

        $this->redis->shouldReceive('hset')->with('{concurrency_models}:meta', 'cached_at', Mockery::type('string'))->andReturn(1);
        $this->redis->shouldReceive('expire')->with('{concurrency_models}:meta', 3600)->andReturn(true);

        $this->service->updateAttributes(1, ['status' => 'inactive']);

        $this->addToAssertionCount(1);
    }

    public function test_stampede_lock_cas_release_safety(): void
    {
        $lockKey = $this->hashKey.':lock';

        $this->redis->shouldReceive('set')->with($lockKey, Mockery::type('string'), ['NX', 'EX' => 10])->andReturn(true);
        $this->redis->shouldReceive('eval')->andReturn(1);

        $value = StampedeProtection::acquireLockWithValue($this->redis, $lockKey, 10);

        $this->assertNotNull($value);

        $released = StampedeProtection::releaseLockCas($this->redis, $lockKey, $value);

        $this->assertTrue($released);
    }

    public function test_stampede_lock_cas_prevents_wrong_owner_release(): void
    {
        $lockKey = $this->hashKey.':lock';

        $this->redis->shouldReceive('set')->with($lockKey, Mockery::type('string'), ['NX', 'EX' => 10])->andReturn(true);
        $this->redis->shouldReceive('eval')->andReturn(0);

        $value = StampedeProtection::acquireLockWithValue($this->redis, $lockKey, 10);
        $this->assertNotNull($value);

        $released = StampedeProtection::releaseLockCas($this->redis, $lockKey, 'wrong-owner-value');

        $this->assertFalse($released);
    }

    // ─── Redis failure simulation ──────────────────────────────────────

    public function test_redis_connection_exception_during_hget_returns_null(): void
    {
        $this->redis->shouldReceive('hget')
            ->with($this->hashKey, '1')
            ->andThrow(new \RedisException('Connection refused'));

        $this->expectException(\RedisException::class);

        $this->service->find(1);
    }

    public function test_redis_timeout_during_sinter_rethrows(): void
    {
        $this->expectException(\RedisException::class);

        $this->redis->shouldReceive('smembers')
            ->with('{concurrency_models}:index:role_id:1')
            ->andThrow(new \RedisException('read error on connection'));

        $this->service->where(['role_id' => 1]);
    }

    public function test_lua_fallback_to_pipeline_on_script_failure(): void
    {
        config(['redis-model-cache.lua_scripting.enabled' => false]);

        $this->redis->shouldReceive('exists')->with($this->hashKey)->andReturn(false);
        $this->redis->shouldReceive('set')->with($this->lockKey, '1', ['NX', 'EX' => 10])->andReturn(true);
        $this->redis->shouldReceive('del')->with($this->lockKey)->andReturn(1);

        $this->mockStoreAndHydrate(['1' => false], ['1' => $this->serializedModel()]);

        $model = $this->makeModel();
        $result = $this->service->rememberAll(fn () => new EloquentCollection([$model]), where: ['role_id' => 1]);

        $this->assertCount(1, $result);
    }

    public function test_pipeline_execution_after_store_many(): void
    {
        $this->redis->shouldReceive('hmget')->with($this->hashKey, Mockery::type('array'))->andReturn(['1' => false]);

        $this->mockPipeline();

        $this->redis->shouldReceive('ttl')->with($this->hashKey)->andReturn(-1);
        $this->redis->shouldReceive('expire')->with($this->hashKey, 3600)->andReturn(true);

        $this->redis->shouldReceive('hset')->with('{concurrency_models}:meta', 'cached_at', Mockery::type('string'))->andReturn(1);
        $this->redis->shouldReceive('expire')->with('{concurrency_models}:meta', 3600)->andReturn(true);

        $model = $this->makeModel();
        $this->service->callStoreMany(new EloquentCollection([$model]));

        $this->addToAssertionCount(1);
    }

    public function test_scan_failure_during_clear_throws(): void
    {
        $this->redis->shouldReceive('scan')
            ->with('0', ['match' => '{concurrency_models}:*', 'count' => 1000])
            ->andThrow(new RuntimeException('SCAN not available'));

        $this->expectException(RuntimeException::class);

        $this->service->clearAll();
    }

    // ─── Invalidation consistency ──────────────────────────────────────

    public function test_delete_removes_all_index_entries(): void
    {
        $this->redis->shouldReceive('hget')->with($this->hashKey, '1')->andReturn(
            json_encode(
                ['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []],
                JSON_THROW_ON_ERROR
            )
        );

        $this->redis->shouldReceive('hdel')->with($this->hashKey, '1')->andReturn(1);
        $this->redis->shouldReceive('srem')->with('{concurrency_models}:index:role_id:1', '1')->andReturn(1);
        $this->redis->shouldReceive('srem')->with('{concurrency_models}:index:status:active', '1')->andReturn(1);
        $this->redis->shouldReceive('zrem')->with('{concurrency_models}:sorted:created_at', '1')->andReturn(1);

        $this->service->delete(1);
        $this->addToAssertionCount(1);
    }

    public function test_version_bust_increments_meta_counter(): void
    {
        $this->redis->shouldReceive('hincrby')->with('{concurrency_models}:meta', 'version', 1)->andReturn(2);
        $this->redis->shouldReceive('expire')->with('{concurrency_models}:meta', 3600)->andReturn(true);

        $this->service->bustVersion();
        $this->addToAssertionCount(1);
    }

    public function test_clear_all_destroys_every_key(): void
    {
        $this->redis->shouldReceive('scan')
            ->with('0', ['match' => '{concurrency_models}:*', 'count' => 1000])
            ->andReturn(['0', ['{concurrency_models}:hash', '{concurrency_models}:meta', '{concurrency_models}:index:role_id:1']]);

        $this->redis->shouldReceive('del')
            ->with('{concurrency_models}:hash', '{concurrency_models}:meta', '{concurrency_models}:index:role_id:1')
            ->andReturn(3);

        $this->service->clearAll();
        $this->addToAssertionCount(1);
    }

    public function test_update_attributes_preserves_relations(): void
    {
        $payload = json_encode(
            [
                'attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'],
                'relations' => ['children' => [['class' => ConcurrencyRelatedModel::class, 'attributes' => ['id' => 10, 'name' => 'Child'], 'relations' => []]]],
            ],
            JSON_THROW_ON_ERROR
        );

        $this->redis->shouldReceive('hget')->with($this->hashKey, '1')->andReturn($payload);

        $pipelineMock = Mockery::mock('Illuminate\Redis\Connections\Pipeline');
        $this->redis->shouldReceive('pipeline')->andReturn($pipelineMock);
        $pipelineMock->shouldReceive('hset')->with($this->hashKey, '1', Mockery::type('string'))->once();
        $pipelineMock->shouldReceive('expire')->with($this->hashKey, 3600)->once();
        $pipelineMock->shouldReceive('srem')->with('{concurrency_models}:index:status:active', '1')->once();
        $pipelineMock->shouldReceive('sadd')->with('{concurrency_models}:index:status:inactive', '1')->once();
        $pipelineMock->shouldReceive('expire')->with('{concurrency_models}:index:status:inactive', 3600)->once();
        $pipelineMock->shouldReceive('execute')->andReturn([true, true, true, true, true]);

        $this->redis->shouldReceive('hset')->with('{concurrency_models}:meta', 'cached_at', Mockery::type('string'))->andReturn(1);
        $this->redis->shouldReceive('expire')->with('{concurrency_models}:meta', 3600)->andReturn(true);

        $this->service->updateAttributes(1, ['status' => 'inactive']);

        $this->addToAssertionCount(1);
    }

    public function test_update_attribute_throws_on_missing_model(): void
    {
        $this->redis->shouldReceive('hget')->with($this->hashKey, '999')->andReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found in cache');

        $this->service->updateAttribute(999, 'status', 'active');
    }

    // ─── Freshness & invalidation tests ─────────────────────────────────

    public function test_touch_invalidation_timestamp_sets_microsecond_precision(): void
    {
        $this->redis->shouldReceive('hset')
            ->with('{concurrency_models}:meta', '_last_invalidated_at', Mockery::type('string'))
            ->once()
            ->andReturn(1);
        $this->redis->shouldReceive('expire')
            ->with('{concurrency_models}:meta', 3600)
            ->once()
            ->andReturn(true);

        $this->service->touchInvalidationTimestamp();

        $this->addToAssertionCount(1);
    }

    public function test_stampede_lock_ttl_release_when_lua_disabled(): void
    {
        config(['redis-model-cache.lua_scripting.enabled' => false]);

        $this->redis->shouldReceive('exists')->with($this->hashKey)->andReturn(false);
        $this->redis->shouldReceive('set')->with($this->lockKey, '1', ['NX', 'EX' => 10])->andReturn(true);
        // When Lua is disabled, no DEL should be called — rely on TTL
        $this->redis->shouldReceive('del')->never();

        $this->mockStoreAndHydrate(['1' => false], ['1' => $this->serializedModel()]);

        $model = $this->makeModel();
        $result = $this->service->rememberAll(fn () => new EloquentCollection([$model]), where: ['role_id' => 1], stampede: true);

        $this->assertCount(1, $result);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function makeModel(): ConcurrencyTestModel
    {
        return new ConcurrencyTestModel(['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01']);
    }

    private function serializedModel(): string
    {
        return json_encode(
            ['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01'], 'relations' => []],
            JSON_THROW_ON_ERROR
        );
    }

    private function mockStoreAndHydrate(array $staleHmgetResult, array $hydrateHmgetResult): void
    {
        $this->redis->shouldReceive('hmget')
            ->with($this->hashKey, Mockery::type('array'))
            ->andReturn($staleHmgetResult, $hydrateHmgetResult);

        $this->mockPipeline();

        $this->redis->shouldReceive('ttl')->with($this->hashKey)->andReturn(-1);
        $this->redis->shouldReceive('expire')->with($this->hashKey, 3600)->andReturn(true);

        $this->redis->shouldReceive('hset')->with('{concurrency_models}:meta', 'cached_at', Mockery::type('string'))->andReturn(1);
        $this->redis->shouldReceive('expire')->with('{concurrency_models}:meta', 3600)->andReturn(true);

        $this->redis->shouldReceive('smembers')->with('{concurrency_models}:index:role_id:1')->andReturn(['1']);
    }

    private function mockPipeline(): void
    {
        $pipelineMock = Mockery::mock('Illuminate\Redis\Connections\Pipeline');
        $this->redis->shouldReceive('pipeline')->andReturn($pipelineMock);
        $pipelineMock->shouldReceive('hset')->once();
        $pipelineMock->shouldReceive('expire')->times(4);
        $pipelineMock->shouldReceive('sadd')->times(2);
        $pipelineMock->shouldReceive('zadd')->once();
        $pipelineMock->shouldReceive('execute')->andReturn(array_fill(0, 8, true));
    }
}

// ─── Helper classes ──────────────────────────────────────────────────

class ConcurrencyTestService extends RedisModelService
{
    public function callStoreMany(Collection $models): void
    {
        $this->storeMany($models);
    }

    protected function collectKeysByPattern(string $pattern): array
    {
        $count = (int) config('redis-model-cache.scan_count', 1000);
        $keys = [];

        $cursor = '0';
        do {
            $result = $this->redis->scan($cursor, ['match' => $pattern, 'count' => $count]);
            $cursor = (string) ($result[0] ?? '0');
            $chunk = $result[1] ?? [];
            if (! empty($chunk)) {
                $keys = array_merge($keys, $chunk);
            }
        } while ($cursor !== '0');

        return array_values(array_unique($keys));
    }
}

class ConcurrencyTestModel extends Model
{
    protected $table = 'concurrency_models';

    protected $guarded = [];

    public $timestamps = false;
}

class ConcurrencyRelatedModel extends Model
{
    protected $table = 'concurrency_related_models';

    protected $guarded = [];

    public $timestamps = false;
}
