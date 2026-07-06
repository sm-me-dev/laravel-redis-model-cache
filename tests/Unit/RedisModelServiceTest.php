<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mockery;
use Mockery\MockInterface;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Tests\TestCase;

class RedisModelServiceTest extends TestCase
{
    private RedisConnectionResolver|MockInterface $connectionResolver;

    private MockInterface $redis;

    private TestableRedisModelService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Lua scripting for unit tests — pipeline tests verify individual
        // Redis commands directly. Lua-level atomicity is integration-tested
        // separately via the feature tests.
        config()->set('redis-model-cache.lua_scripting.enabled', false);

        $this->redis = Mockery::mock('Illuminate\Redis\Connections\Connection');
        $this->connectionResolver = Mockery::mock(RedisConnectionResolver::class);
        $this->connectionResolver->shouldReceive('resolve')->andReturn($this->redis);
        $this->connectionResolver->shouldReceive('getPrefix')->andReturn('');

        $matchStrategy = Mockery::mock(ModelMatchStrategy::class);
        $matchStrategy->shouldReceive('normalize')->andReturnUsing(fn ($v) => $v);
        $matchStrategy->shouldReceive('matches')->andReturnUsing(fn ($a, $b) => $a === $b);

        $this->service = new TestableRedisModelService(
            connectionResolver: $this->connectionResolver,
            model_class: TestModel::class,
            indexes: ['role_id', 'status'],
            sorted: ['created_at'],
            custom_indexes: ['active_admins' => ['role_id' => 1, 'status' => 'active']],
            ttl: 3600,
            matchStrategy: $matchStrategy
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_all_throws_bad_method_call_exception(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('all() is disabled');

        $this->service->all();
    }

    public function test_where_throws_bad_method_call_exception_on_empty_where_with_warm_cache(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Global unindexed cache fetches via rememberAll() are prohibited');

        $this->redis->shouldReceive('exists')->with('{test_models}:hash')->andReturn(true);

        $this->service->rememberAll(fn () => new Collection([]), where: []);
    }

    public function test_where_throws_invalid_argument_exception_on_unindexed_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Field 'email' is not indexed");

        $this->service->where(['email' => 'test@example.com']);
    }

    public function test_store_many_executes_exactly_one_pipeline_call(): void
    {
        $pipelineMock = Mockery::mock('Illuminate\Redis\Connections\Pipeline');
        $this->redis->shouldReceive('pipeline')->andReturn($pipelineMock);

        // Stale index check reads old data before pipeline (batched HMGET)
        $this->redis->shouldReceive('hmget')->with('{test_models}:hash', Mockery::type('array'))->andReturn(['1' => false, '2' => false]);

        $pipelineMock->shouldReceive('hset')->times(2);
        $pipelineMock->shouldReceive('expire')->with('{test_models}:hash', 3600)->times(2);
        $pipelineMock->shouldReceive('sadd')->times(4);
        $pipelineMock->shouldReceive('expire')->with('{test_models}:index:role_id:1', 3600)->once();
        $pipelineMock->shouldReceive('expire')->with('{test_models}:index:role_id:2', 3600)->once();
        $pipelineMock->shouldReceive('expire')->with('{test_models}:index:status:active', 3600)->once();
        $pipelineMock->shouldReceive('expire')->with('{test_models}:index:status:inactive', 3600)->once();
        $pipelineMock->shouldReceive('zadd')->times(2);
        $pipelineMock->shouldReceive('expire')->with('{test_models}:sorted:created_at', 3600)->times(2);
        $pipelineMock->shouldReceive('execute')->once()->andReturn(array_fill(0, 16, true));

        $this->redis->shouldReceive('ttl')->with('{test_models}:hash')->andReturn(-1);
        $this->redis->shouldReceive('expire')->with('{test_models}:hash', 3600)->andReturn(true);

        // storeCacheMetadata() calls hset + expire on the meta key after pipeline
        $this->redis->shouldReceive('hset')->with('{test_models}:meta', 'cached_at', Mockery::type('string'))->andReturn(1);
        $this->redis->shouldReceive('expire')->with('{test_models}:meta', 3600)->andReturn(true);

        $models = new Collection([
            new TestModel(['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01']),
            new TestModel(['id' => 2, 'role_id' => 2, 'status' => 'inactive', 'created_at' => '2024-01-02']),
        ]);

        $this->service->callStoreMany($models);

        $this->addToAssertionCount(1);
    }

    public function test_has_many_relation_hydration_without_extra_queries(): void
    {
        $model = new TestModel(['id' => 1, 'role_id' => 1, 'status' => 'active']);
        $related = new RelatedModel(['id' => 10, 'parent_id' => 1, 'name' => 'Child']);

        $model->setRelation('children', new Collection([$related]));

        $payload = [
            'attributes' => $model->getAttributes(),
            'relations' => [
                'children' => [
                    [
                        'class' => RelatedModel::class,
                        'attributes' => $related->getAttributes(),
                        'relations' => [],
                    ],
                ],
            ],
        ];

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->redis->shouldReceive('smembers')->with('{test_models}:index:role_id:1')->andReturn(['1']);
        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn(['1' => $serialized]);

        $result = $this->service->where(['role_id' => 1]);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(TestModel::class, $result->first());
        $this->assertCount(1, $result->first()->getRelation('children'));
        $this->assertInstanceOf(RelatedModel::class, $result->first()->getRelation('children')->first());
        $this->assertEquals('Child', $result->first()->getRelation('children')->first()->name);
    }

    public function test_collect_keys_by_pattern_returns_unique_keys(): void
    {
        $this->redis->shouldReceive('scan')
            ->with('0', ['match' => '{test_models}:*', 'count' => 1000])
            ->andReturn(['100', ['{test_models}:hash', '{test_models}:index:role_id:1']])
            ->once();
        $this->redis->shouldReceive('scan')
            ->with('100', ['match' => '{test_models}:*', 'count' => 1000])
            ->andReturn(['0', ['{test_models}:index:status:active', '{test_models}:hash']])
            ->once();

        $keys = $this->service->callCollectKeysByPattern('{test_models}:*');

        $this->assertIsArray($keys);
        $this->assertCount(3, $keys);
        $this->assertEquals(
            ['{test_models}:hash', '{test_models}:index:role_id:1', '{test_models}:index:status:active'],
            array_values(array_unique($keys))
        );
    }

    public function test_where_with_indexed_field_returns_models(): void
    {
        $this->redis->shouldReceive('smembers')->with('{test_models}:index:role_id:1')->andReturn(['1', '2']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '1' => json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR),
                '2' => json_encode(['attributes' => ['id' => 2, 'role_id' => 1, 'status' => 'inactive'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->where(['role_id' => 1]);

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result->first()->getKey());
    }

    public function test_remember_all_throws_on_empty_where_with_warm_cache(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Global unindexed cache fetches via rememberAll() are prohibited');

        $this->redis->shouldReceive('exists')->with('{test_models}:hash')->andReturn(true);

        $this->service->rememberAll(fn () => new Collection([]), where: []);
    }

    public function test_remember_index_uses_index_lookup(): void
    {
        $this->redis->shouldReceive('exists')->with('{test_models}:index:role_id:1')->andReturn(true);
        $this->redis->shouldReceive('smembers')->with('{test_models}:index:role_id:1')->andReturn(['1']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '1' => json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->rememberIndex('role_id', 1, fn () => new Collection([]));

        $this->assertCount(1, $result);
    }

    public function test_custom_where_uses_intersection(): void
    {
        $this->redis->shouldReceive('sinter')->with('{test_models}:custom:active_admins')->andReturn(['1', '2']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '1' => json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR),
                '2' => json_encode(['attributes' => ['id' => 2, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->customWhere(['active_admins']);

        $this->assertCount(2, $result);
    }

    public function test_delete_removes_from_hash_and_indexes(): void
    {
        $this->redis->shouldReceive('hget')->with('{test_models}:hash', '1')->andReturn(
            json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR)
        );
        $this->redis->shouldReceive('hdel')->with('{test_models}:hash', '1')->andReturn(1);
        $this->redis->shouldReceive('srem')->with('{test_models}:index:role_id:1', '1')->andReturn(1);
        $this->redis->shouldReceive('srem')->with('{test_models}:index:status:active', '1')->andReturn(1);
        $this->redis->shouldReceive('zrem')->with('{test_models}:sorted:created_at', '1')->andReturn(1);

        $this->service->delete(1);

        $this->addToAssertionCount(1);
    }

    public function test_clear_all_uses_collect_keys_by_pattern(): void
    {
        $this->redis->shouldReceive('scan')
            ->with('0', ['match' => '{test_models}:*', 'count' => 1000])
            ->andReturn(['0', ['{test_models}:hash', '{test_models}:index:role_id:1']])
            ->once();
        $this->redis->shouldReceive('del')->with('{test_models}:hash', '{test_models}:index:role_id:1')->andReturn(2);

        $this->service->clearAll();

        $this->addToAssertionCount(1);
    }

    public function test_remember_throws_on_non_indexed_find_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not indexed');

        $this->redis->shouldReceive('exists')->with('{test_models}:hash')->andReturn(false);

        // Stale index check (batched HMGET)
        $this->redis->shouldReceive('hmget')->with('{test_models}:hash', Mockery::type('array'))->andReturn(['1' => false]);

        $pipelineMock = Mockery::mock('Illuminate\Redis\Connections\Pipeline');
        $this->redis->shouldReceive('pipeline')->andReturn($pipelineMock);
        $pipelineMock->shouldReceive('hset')->once();
        $pipelineMock->shouldReceive('expire')->with('{test_models}:hash', 3600)->once();
        $pipelineMock->shouldReceive('sadd')->once();
        $pipelineMock->shouldReceive('expire')->with('{test_models}:index:role_id:1', 3600)->once();
        $pipelineMock->shouldReceive('execute')->andReturn([true, true, true, true]);
        $this->redis->shouldReceive('ttl')->with('{test_models}:hash')->andReturn(-1);
        $this->redis->shouldReceive('expire')->with('{test_models}:hash', 3600)->andReturn(true);
        $this->redis->shouldReceive('hset')->with('{test_models}:meta', 'cached_at', Mockery::type('string'))->andReturn(1);
        $this->redis->shouldReceive('expire')->with('{test_models}:meta', 3600)->andReturn(true);

        $this->service->remember(
            fn () => new Collection([new TestModel(['id' => 1, 'role_id' => 1])]),
            findBy: 'email',
            findValue: 'test@example.com'
        );
    }

    public function test_sorted_returns_models_in_order(): void
    {
        $this->redis->shouldReceive('zrevrange')->with('{test_models}:sorted:created_at', 0, 9)->andReturn(['2', '1']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '2' => json_encode(['attributes' => ['id' => 2, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-02'], 'relations' => []], JSON_THROW_ON_ERROR),
                '1' => json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->sorted('created_at', 0, 9);

        $this->assertCount(2, $result);
        $this->assertEquals(2, $result->first()->getKey());
    }

    public function test_paginate_sorted_calls_sorted_with_correct_range(): void
    {
        $this->redis->shouldReceive('zrevrange')->with('{test_models}:sorted:created_at', 10, 19)->andReturn(['3']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '3' => json_encode(['attributes' => ['id' => 3, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-03'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->paginateSorted('created_at', 2, 10);

        $this->assertCount(1, $result);
    }

    public function test_store_model_extracts_and_restores_has_many_relation(): void
    {
        $parent = new TestModel(['id' => 1, 'role_id' => 1, 'status' => 'active']);
        $child1 = new RelatedModel(['id' => 10, 'parent_id' => 1, 'name' => 'Child 1']);
        $child2 = new RelatedModel(['id' => 11, 'parent_id' => 1, 'name' => 'Child 2']);

        $parent->setRelation('children', new Collection([$child1, $child2]));

        // Stale index check reads old data
        $this->redis->shouldReceive('hget')->with('{test_models}:hash', '1')->andReturn(false);

        $pipelineMock = Mockery::mock('Illuminate\Redis\Connections\Pipeline');

        $pipelineMock->shouldReceive('hset')->once();
        $pipelineMock->shouldReceive('expire')->times(3);
        $pipelineMock->shouldReceive('sadd')->times(2);
        $pipelineMock->shouldReceive('execute')->andReturn([true, true, true, true, true, true]);

        $this->service->callStoreModel($parent, $pipelineMock);

        $this->addToAssertionCount(1);
    }

    public function test_scan_handles_predis_client_format(): void
    {
        $predisClient = Mockery::mock('Predis\Client');
        $predisClient->shouldReceive('scan')
            ->with('0', ['match' => '{test_models}:*', 'count' => 1000])
            ->andReturn(['100', ['{test_models}:hash', '{test_models}:index:role_id:1']])
            ->once();
        $predisClient->shouldReceive('scan')
            ->with('100', ['match' => '{test_models}:*', 'count' => 1000])
            ->andReturn(['0', ['{test_models}:index:status:active']])
            ->once();

        $resolver = Mockery::mock(RedisConnectionResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($predisClient);
        $resolver->shouldReceive('getPrefix')->andReturn('');

        $matchStrategy = Mockery::mock(ModelMatchStrategy::class);
        $matchStrategy->shouldReceive('normalize')->andReturnUsing(fn ($v) => $v);
        $matchStrategy->shouldReceive('matches')->andReturnUsing(fn ($a, $b) => $a === $b);

        $service = new TestableRedisModelService(
            connectionResolver: $resolver,
            model_class: TestModel::class,
            indexes: ['role_id'],
            ttl: null,
            matchStrategy: $matchStrategy
        );

        $keys = $service->callCollectKeysByPattern('{test_models}:*');

        $this->assertCount(3, $keys);
        $this->assertEquals(
            ['{test_models}:hash', '{test_models}:index:role_id:1', '{test_models}:index:status:active'],
            array_values(array_unique($keys))
        );
    }

    public function test_remember_uses_index_lookup_when_field_indexed(): void
    {
        $this->redis->shouldReceive('exists')->with('{test_models}:hash')->andReturn(false);
        $this->redis->shouldReceive('pipeline')->andReturn(
            Mockery::mock('Illuminate\Redis\Connections\Pipeline')
                ->shouldReceive('execute')->andReturn([])
                ->getMock()
        );

        $this->redis->shouldReceive('smembers')->with('{test_models}:index:role_id:1')->andReturn([]);

        $result = $this->service->remember(fn () => new Collection([]), findBy: 'role_id', findValue: 1);

        $this->assertNull($result);
    }

    public function test_store_model_prunes_stale_indexes_when_indexed_field_changes(): void
    {
        $model = new TestModel(['id' => 1, 'role_id' => 2, 'status' => 'active', 'created_at' => '2024-01-01']);

        $oldPayload = json_encode([
            'attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01'],
            'relations' => [],
        ], JSON_THROW_ON_ERROR);

        $this->redis->shouldReceive('hget')
            ->with('{test_models}:hash', '1')
            ->andReturn($oldPayload);

        // Should SREM old role_id:1 index (role_id changed from 1 to 2)
        $this->redis->shouldReceive('srem')
            ->with('{test_models}:index:role_id:1', '1')
            ->andReturn(1);

        // Should NOT SREM status:active (status didn't change)
        $this->redis->shouldReceive('hset')
            ->with('{test_models}:hash', '1', Mockery::type('string'))
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:hash', 3600)
            ->andReturn(1);

        // New index for role_id:2
        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:role_id:2', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:role_id:2', 3600)
            ->andReturn(1);

        // status:active index should still be added (unchanged)
        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:status:active', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:status:active', 3600)
            ->andReturn(1);

        // Sorted set for created_at
        $this->redis->shouldReceive('zadd')
            ->with('{test_models}:sorted:created_at', Mockery::type('float'), '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:sorted:created_at', 3600)
            ->andReturn(1);

        $this->service->callStoreModel($model);

        $this->addToAssertionCount(1);
    }

    public function test_store_model_applies_ttl_to_hash_index_and_sorted_keys(): void
    {
        $model = new TestModel(['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01']);

        // No old data
        $this->redis->shouldReceive('hget')
            ->with('{test_models}:hash', '1')
            ->andReturn(false);

        $this->redis->shouldReceive('hset')
            ->with('{test_models}:hash', '1', Mockery::type('string'))
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:hash', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:role_id:1', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:role_id:1', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:status:active', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:status:active', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('zadd')
            ->with('{test_models}:sorted:created_at', Mockery::type('float'), '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:sorted:created_at', 3600)
            ->andReturn(1);

        $this->service->callStoreModel($model);

        $this->addToAssertionCount(1);
    }

    public function test_store_model_skips_stale_prune_when_no_old_data(): void
    {
        $model = new TestModel(['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01']);

        $this->redis->shouldReceive('hget')
            ->with('{test_models}:hash', '1')
            ->andReturn(false);

        $this->redis->shouldReceive('hset')
            ->with('{test_models}:hash', '1', Mockery::type('string'))
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:hash', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:role_id:1', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:role_id:1', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:status:active', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:status:active', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('zadd')
            ->with('{test_models}:sorted:created_at', Mockery::type('float'), '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:sorted:created_at', 3600)
            ->andReturn(1);

        $this->service->callStoreModel($model);

        $this->addToAssertionCount(1);
    }

    public function test_store_model_handles_old_format_payload_for_stale_index_prune(): void
    {
        $model = new TestModel(['id' => 1, 'role_id' => 2, 'status' => 'active', 'created_at' => '2024-01-01']);

        // Old format: flat attributes without 'attributes' wrapper
        $oldPayload = json_encode([
            'id' => 1,
            'role_id' => 1,
            'status' => 'active',
            'created_at' => '2024-01-01',
        ], JSON_THROW_ON_ERROR);

        $this->redis->shouldReceive('hget')
            ->with('{test_models}:hash', '1')
            ->andReturn($oldPayload);

        $this->redis->shouldReceive('srem')
            ->with('{test_models}:index:role_id:1', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('hset')
            ->with('{test_models}:hash', '1', Mockery::type('string'))
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:hash', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:role_id:2', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:role_id:2', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:status:active', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:status:active', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('zadd')
            ->with('{test_models}:sorted:created_at', Mockery::type('float'), '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:sorted:created_at', 3600)
            ->andReturn(1);

        $this->service->callStoreModel($model);

        $this->addToAssertionCount(1);
    }

    public function test_store_model_skips_stale_prune_when_indexed_value_unchanged(): void
    {
        $model = new TestModel(['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01']);

        $oldPayload = json_encode([
            'attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01'],
            'relations' => [],
        ], JSON_THROW_ON_ERROR);

        $this->redis->shouldReceive('hget')
            ->with('{test_models}:hash', '1')
            ->andReturn($oldPayload);

        // Should NOT call srem (no values changed)

        $this->redis->shouldReceive('hset')
            ->with('{test_models}:hash', '1', Mockery::type('string'))
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:hash', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:role_id:1', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:role_id:1', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:status:active', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:status:active', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('zadd')
            ->with('{test_models}:sorted:created_at', Mockery::type('float'), '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:sorted:created_at', 3600)
            ->andReturn(1);

        $this->service->callStoreModel($model);

        $this->addToAssertionCount(1);
    }

    public function test_delete_removed_from_hash_and_indexes_and_sorted(): void
    {
        $this->redis->shouldReceive('hget')->with('{test_models}:hash', '1')->andReturn(
            json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR)
        );
        $this->redis->shouldReceive('hdel')->with('{test_models}:hash', '1')->andReturn(1);
        $this->redis->shouldReceive('srem')->with('{test_models}:index:role_id:1', '1')->andReturn(1);
        $this->redis->shouldReceive('srem')->with('{test_models}:index:status:active', '1')->andReturn(1);
        $this->redis->shouldReceive('zrem')->with('{test_models}:sorted:created_at', '1')->andReturn(1);

        $this->service->delete(1);

        $this->addToAssertionCount(1);
    }

    public function test_remove_custom_indexes_cleans_all_custom_sets(): void
    {
        $this->redis->shouldReceive('srem')->with('{test_models}:custom:active_admins', '1')->andReturn(1);

        $this->service->removeCustomIndexes(1, []);

        $this->addToAssertionCount(1);
    }

    public function test_bust_version_increments_meta_hash_and_sets_ttl(): void
    {
        $this->redis->shouldReceive('hincrby')->with('{test_models}:meta', 'version', 1)->andReturn(2);
        $this->redis->shouldReceive('expire')->with('{test_models}:meta', 3600)->andReturn(true);

        $this->service->bustVersion();

        $this->addToAssertionCount(1);
    }

    public function test_custom_returns_models_from_custom_index(): void
    {
        $this->redis->shouldReceive('smembers')->with('{test_models}:custom:active_admins')->andReturn(['1', '2']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '1' => json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR),
                '2' => json_encode(['attributes' => ['id' => 2, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->custom('active_admins');

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result->first()->getKey());
    }

    public function test_custom_returns_empty_collection_when_no_ids(): void
    {
        $this->redis->shouldReceive('smembers')->with('{test_models}:custom:active_admins')->andReturn([]);

        $result = $this->service->custom('active_admins');

        $this->assertCount(0, $result);
    }

    public function test_remember_custom_uses_index_lookup_when_exists(): void
    {
        $this->redis->shouldReceive('exists')->with('{test_models}:custom:active_admins')->andReturn(true);
        $this->redis->shouldReceive('smembers')->with('{test_models}:custom:active_admins')->andReturn(['1']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '1' => json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->rememberCustom('active_admins', fn () => new Collection([]));

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result->first()->getKey());
    }

    public function test_remember_custom_populates_index_when_missing(): void
    {
        $this->redis->shouldReceive('exists')->with('{test_models}:custom:active_admins')->andReturn(false);

        // storeModel calls: hget for stale check, hset, expire, sadd (×2), expire (×2), zadd, expire
        $this->redis->shouldReceive('hget')
            ->with('{test_models}:hash', '1')
            ->andReturn(false);

        $this->redis->shouldReceive('hset')
            ->with('{test_models}:hash', '1', Mockery::type('string'))
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:hash', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:role_id:1', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:role_id:1', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:index:status:active', '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:index:status:active', 3600)
            ->andReturn(1);

        $this->redis->shouldReceive('zadd')
            ->with('{test_models}:sorted:created_at', Mockery::type('float'), '1')
            ->andReturn(1);

        $this->redis->shouldReceive('expire')
            ->with('{test_models}:sorted:created_at', 3600)
            ->andReturn(1);

        // Custom index sadd after storeModel
        $this->redis->shouldReceive('sadd')
            ->with('{test_models}:custom:active_admins', '1')
            ->andReturn(1);

        // applyTTL for custom key
        $this->redis->shouldReceive('ttl')->with('{test_models}:custom:active_admins')->andReturn(-1);
        $this->redis->shouldReceive('expire')->with('{test_models}:custom:active_admins', 3600)->andReturn(1);

        $model = new TestModel(['id' => 1, 'role_id' => 1, 'status' => 'active']);
        $result = $this->service->rememberCustom('active_admins', fn () => new Collection([$model]));

        $this->assertCount(1, $result);
    }

    public function test_remember_custom_with_sort_by_uses_sorted_set(): void
    {
        $this->redis->shouldReceive('exists')->with('{test_models}:custom:active_admins:sorted:created_at')->andReturn(true);
        $this->redis->shouldReceive('zrange')->with('{test_models}:custom:active_admins:sorted:created_at', 0, -1)->andReturn(['2', '1']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '2' => json_encode(['attributes' => ['id' => 2, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-02'], 'relations' => []], JSON_THROW_ON_ERROR),
                '1' => json_encode(['attributes' => ['id' => 1, 'role_id' => 1, 'status' => 'active', 'created_at' => '2024-01-01'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->rememberCustom('active_admins', fn () => new Collection([]), sortBy: 'created_at');

        $this->assertCount(2, $result);
        $this->assertEquals(2, $result->first()->getKey()); // Sorted by created_at DESC
    }

    public function test_sorted_returns_models_in_reverse_score_order(): void
    {
        $this->redis->shouldReceive('zrevrange')->with('{test_models}:sorted:created_at', 0, 9)->andReturn(['2', '1']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '2' => json_encode(['attributes' => ['id' => 2, 'created_at' => '2024-01-02'], 'relations' => []], JSON_THROW_ON_ERROR),
                '1' => json_encode(['attributes' => ['id' => 1, 'created_at' => '2024-01-01'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->sorted('created_at', 0, 9);

        $this->assertCount(2, $result);
        $this->assertEquals(2, $result->first()->getKey());
    }

    public function test_paginate_sorted_calculates_correct_range(): void
    {
        $this->redis->shouldReceive('zrevrange')->with('{test_models}:sorted:created_at', 10, 19)->andReturn(['3']);

        $this->redis->shouldReceive('hmget')
            ->with('{test_models}:hash', Mockery::type('array'))
            ->andReturn([
                '3' => json_encode(['attributes' => ['id' => 3, 'created_at' => '2024-01-03'], 'relations' => []], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->service->paginateSorted('created_at', 2, 10);

        $this->assertCount(1, $result);
        $this->assertEquals(3, $result->first()->getKey());
    }
}

class TestableRedisModelService extends RedisModelService
{
    public function callStoreMany(Collection $models): void
    {
        $this->storeMany($models);
    }

    public function callCollectKeysByPattern(string $pattern): array
    {
        return $this->collectKeysByPattern($pattern);
    }

    public function callStoreModel(Model $model, $pipeline = null): void
    {
        $this->storeModel($model, $pipeline);
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

class TestModel extends Model
{
    protected $table = 'test_models';

    protected $guarded = [];

    public $timestamps = false;

    public function children(): HasMany
    {
        return $this->hasMany(RelatedModel::class, 'parent_id');
    }
}

class RelatedModel extends Model
{
    protected $table = 'related_models';

    protected $guarded = [];

    public $timestamps = false;
}
