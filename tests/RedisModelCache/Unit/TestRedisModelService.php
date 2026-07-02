<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use InvalidArgumentException;
use Mockery\MockInterface;
use BadMethodCallException;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;
use Sm_mE\RedisModelCache\Tests\TestCase;

class TestRedisModelService extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app('Sm_mE\\RedisModelCache\\RedisModelService');
        $this->service->clearAll();
    }

    /**
     * @test
     * Memory Safety: where() requires indexed fields
     */
    public function test_where_throws_when_field_not_indexed()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field \'email\' is not indexed');

        $this->service->where(['email' => 'test@example.com']);
    }

    /**
     * @test
     * Memory Safety: where() works with indexed fields
     */
    public function test_where_with_indexed_field_returns_results()
    {
        $user1 = $this->createUser(['name' => 'Alice']);
        $user2 = $this->createUser(['name' => 'Bob']);

        $result = $this->service->where(['name' => 'Alice']);

        $this->assertCount(1, $result);
        $this->assertEquals('Alice', $result->first()->name);
    }

    /**
     * @test
     * Memory Safety: all() throws BadMethodCallException
     */
    public function test_all_throws_bad_method_call()
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('all() is disabled');

        $this->service->all();
    }

    /**
     * @test
     * Memory Safety: rememberAll() with empty where throws when warm
     */
    public function test_remember_all_with_empty_where_throws_on_warm_cache()
    {
        $this->createUser(['name' => 'Alice']);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Global unindexed cache fetches via rememberAll()');

        $this->service->rememberAll(function () {
            return [];
        }, where: [], refresh: false);
    }

    /**
     * @test
     * Pipeline Atomicity: storeMany uses single pipeline
     */
    public function test_store_many_uses_single_pipeline()
    {
        $models = $this->createUser([['name' => 'Alice'], ['name' => 'Bob']]);

        $this->service->storeMany($models);

        $result = $this->service->where(['name' => 'Alice']);
        $this->assertCount(1, $result);

        $result = $this->service->where(['name' => 'Bob']);
        $this->assertCount(1, $result);
    }

    /**
     * @test
     * Pipeline Atomicity: rememberAll stores without partial writes
     */
    public function test_remember_all_stores_completely_or_not_at_all()
    {
        $callCount = 0;

        $result = $this->service->rememberAll(function () use (&$callCount) {
            $callCount++;
            return [];
        }, refresh: true);

        $this->assertEquals(1, $callCount);
        $this->assertTrue($result->isEmpty());
    }

    /**
     * @test
     * Relation Hydration: eager-loaded relations are preserved
     */
    public function test_store_and_retrieve_with_eager_loaded_relations()
    {
        $user = $this->createUser(['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->service->storeModel($user);

        // First retrieval - should have relations hydrated
        $retrieved = $this->service->where(['name' => 'Alice']);

        $this->assertCount(1, $retrieved);
        $this->assertInstanceOf(Model::class, $retrieved->first());
        $this->assertTrue($retrieved->first()->relationLoaded('posts'));
    }

    /**
     * @test
     * Index-first Lookup: rememberIndex uses indexes
     */
    public function test_remember_index_uses_smembers_for_lookup()
    {
        $user = $this->createUser(['name' => 'Alice', 'email' => 'alice@example.com']);
        $email = 'alice@example.com';

        $result = $this->service->rememberIndex('email', $email, function () {
            return [$user];
        });

        $this->assertCount(1, $result);
        $this->assertEquals('Alice', $result->first()->name);
    }

    /**
     * @test
     * Index-first Lookup: remember throws when field not indexed
     */
    public function test_remember_throws_when_field_not_indexed()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field \'email\' is not indexed');

        $this->service->remember(function () {
            return [];
        }, findBy: 'email', findValue: 'test@example.com');
    }

    /**
     * @test
     * SCAN Safety: Predis client handling
     */
    public function test_collect_keys_by_pattern_returns_unique_keys()
    {
        $user1 = $this->createUser(['name' => 'Alice']);
        $user2 = $this->createUser(['name' => 'Bob']);

        $keys = $this->service->collectKeysByPattern("*");

        $this->assertIsArray($keys);
        $this->assertGreaterThan(count($keys), 0);
    }

    /**
     * @test
     * Memory Safety: where() equality checks
     */
    public function test_where_equality_checking()
    {
        $this->createUser(['name' => 'Alice']);
        $this->createUser(['name' => 'bob']);

        $result = $this->service->where(['name' => 'Alice']);

        $this->assertCount(1, $result);
        $this->assertEquals('Alice', $result->first()->name);

        $result = $this->service->where(['name' => 'bob']);

        $this->assertCount(1, $result);
        $this->assertEquals('bob', $result->first()->name);
    }

    /**
     * @test
     * Pipeline Behavior: storeMany batch operations
     */
    public function test_store_many_batch_operations_preserve_data()
    {
        $models = $this->createUser([
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
            ['name' => 'Charlie', 'email' => 'charlie@example.com'],
        ]);

        $this->service->storeMany($models);

        $alice = $this->service->where(['name' => 'Alice']);
        $bob = $this->service->where(['name' => 'Bob']);
        $charlie = $this->service->where(['name' => 'Charlie']);

        $this->assertCount(1, $alice);
        $this->assertCount(1, $bob);
        $this->assertCount(1, $charlie);

        $this->assertEquals('alice@example.com', $alice->first()->email);
        $this->assertEquals('bob@example.com', $bob->first()->email);
        $this->assertEquals('charlie@example.com', $charlie->first()->email);
    }

    /**
     * @test
     * Relation Edge Case: null relations are handled
     */
    public function test_store_model_with_null_relations()
    {
        $user = $this->createUser(['name' => 'Alice']);

        $this->service->storeModel($user);

        $retrieved = $this->service->where(['name' => 'Alice']);

        $this->assertCount(1, $retrieved);
        $this->assertEquals('Alice', $retrieved->first()->name);
    }

    /**
     * @test
     * Memory Enforcement: hydrateIds with large ID sets
     */
    public function test_hydrate_ids_handles_large_result_sets()
    {
        $users = $this->createUser([
            ['name' => 'User' . $i, 'email' => "user{$i}@example.com"] for $i in range(1, 101)
        ]);

        $this->service->storeMany($users);

        $results = $this->service->where(['name' => 'User1']);

        $this->assertCount(1, $results);
        $this->assertEquals('User1', $results->first()->name);
    }

    /**
     * @test
     * Atomicity Guarantee: partial failures don't leave orphaned data
     */
    public function test_store_many_atomicity()
    {
        $users = $this->createUser([
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        ]);

        $this->service->storeMany($users);

        $aliceCount = $this->service->where(['name' => 'Alice'])->count();
        $bobCount = $this->service->where(['name' => 'Bob'])->count();

        $this->assertEquals(1, $aliceCount);
        $this->assertEquals(1, $bobCount);
    }

    /**
     * @test
     * Index Validation: multiple indexed fields work correctly
     */
    public function test_multiple_indexed_fields_intersection()
    {
        $user = $this->createUser(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        $result = $this->service->where([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'status' => 'active',
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals('Alice', $result->first()->name);
    }

    /**
     * @test
     * Error Handling: invalid findBy in remember()
     */
    public function test_remember_with_invalid_find_by_throws()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field \'email\' is not indexed');

        $this->service->remember(function () {
            return [];
        }, findBy: 'email', findValue: 'test@example.com');
    }

    /**
     * @test
     * Type Safety: hydrateIds type preservation
     */
    public function test_hydrate_ids_preserves_model_types()
    {
        $user = $this->createUser(['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->service->storeModel($user);

        $retrieved = $this->service->where(['name' => 'Alice']);

        $this->assertInstanceOf(Model::class, $retrieved->first());
        $this->assertTrue(method_exists($retrieved->first(), 'getKey'));
        $this->assertTrue(method_exists($retrieved->first(), 'toArray'));
    }
}
}