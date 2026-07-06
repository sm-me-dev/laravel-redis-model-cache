<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Sm_mE\RedisModelCache\Contracts\RedisConnectionResolver;
use Sm_mE\RedisModelCache\Contracts\TenantResolverInterface;
use Sm_mE\RedisModelCache\RedisModelService;
use Sm_mE\RedisModelCache\Support\TenantResolvers\RequestTenantResolver;
use Sm_mE\RedisModelCache\Tests\TestCase;

class MultiTenantTest extends TestCase
{
    private RedisConnectionResolver|MockInterface $connectionResolver;

    private MockInterface $redis;

    private TenantResolverInterface|MockInterface $tenantResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock('Illuminate\Redis\Connections\Connection');
        $this->connectionResolver = Mockery::mock(RedisConnectionResolver::class);
        $this->connectionResolver->shouldReceive('resolve')->andReturn($this->redis);
        $this->connectionResolver->shouldReceive('getPrefix')->andReturn('');

        $this->tenantResolver = Mockery::mock(TenantResolverInterface::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_build_prefix_returns_table_only_when_multi_tenant_disabled(): void
    {
        config(['redis-model-cache.multi_tenant.enabled' => false]);
        $this->app->instance(TenantResolverInterface::class, $this->tenantResolver);

        $service = $this->makeService();

        $this->assertStringContainsString('{test_models}:hash', $service->callHashKey());
        $this->assertStringNotContainsString('tenant:', $service->callHashKey());
    }

    public function test_build_prefix_includes_tenant_id_when_resolved(): void
    {
        config(['redis-model-cache.multi_tenant.enabled' => true]);
        $this->tenantResolver->shouldReceive('getTenantId')->andReturn('42');
        $this->app->instance(TenantResolverInterface::class, $this->tenantResolver);

        $service = $this->makeService();

        $this->assertStringContainsString('tenant:42', $service->callHashKey());
    }

    public function test_build_prefix_falls_back_when_resolver_returns_null(): void
    {
        config(['redis-model-cache.multi_tenant.enabled' => true]);
        $this->tenantResolver->shouldReceive('getTenantId')->andReturn(null);
        $this->app->instance(TenantResolverInterface::class, $this->tenantResolver);

        $service = $this->makeService();

        $this->assertStringContainsString('{test_models}:hash', $service->callHashKey());
        $this->assertStringNotContainsString('tenant:', $service->callHashKey());
    }

    public function test_build_prefix_falls_back_when_resolver_throws(): void
    {
        config(['redis-model-cache.multi_tenant.enabled' => true]);
        $this->tenantResolver->shouldReceive('getTenantId')->andThrow(new \RuntimeException('fail'));
        $this->app->instance(TenantResolverInterface::class, $this->tenantResolver);

        $service = $this->makeService();

        $this->assertStringContainsString('{test_models}:hash', $service->callHashKey());
        $this->assertStringNotContainsString('tenant:', $service->callHashKey());
    }

    public function test_all_key_methods_inherit_tenant_prefix(): void
    {
        config(['redis-model-cache.multi_tenant.enabled' => true]);
        $this->tenantResolver->shouldReceive('getTenantId')->andReturn('acme');
        $this->app->instance(TenantResolverInterface::class, $this->tenantResolver);

        $service = $this->makeService();

        $this->assertStringContainsString('{tenant:acme:test_models}:hash', $service->callHashKey());
        $this->assertStringContainsString('{tenant:acme:test_models}:meta', $service->callMetaKey());
    }

    public function test_different_tenants_produce_different_keys(): void
    {
        config(['redis-model-cache.multi_tenant.enabled' => true]);

        $resolverA = Mockery::mock(TenantResolverInterface::class);
        $resolverA->shouldReceive('getTenantId')->andReturn('tenant_a');
        $this->app->instance(TenantResolverInterface::class, $resolverA);
        $serviceA = $this->makeService();

        $resolverB = Mockery::mock(TenantResolverInterface::class);
        $resolverB->shouldReceive('getTenantId')->andReturn('tenant_b');
        $this->app->instance(TenantResolverInterface::class, $resolverB);
        $serviceB = $this->makeService();

        $this->assertNotSame($serviceA->callHashKey(), $serviceB->callHashKey());
        $this->assertStringContainsString('tenant_a', $serviceA->callHashKey());
        $this->assertStringContainsString('tenant_b', $serviceB->callHashKey());
    }

    public function test_sanitizes_tenant_id_special_chars(): void
    {
        config(['redis-model-cache.multi_tenant.enabled' => true]);
        $this->tenantResolver->shouldReceive('getTenantId')->andReturn('{bad:}:id');
        $this->app->instance(TenantResolverInterface::class, $this->tenantResolver);

        $service = $this->makeService();

        $key = $service->callHashKey();
        // Sanitized: braces removed, colons replaced with underscores
        $this->assertStringContainsString('tenant:bad__id', $key);
        $this->assertStringNotContainsString('{bad:', $key);
    }

    public function test_clear_all_scoped_to_tenant(): void
    {
        config(['redis-model-cache.multi_tenant.enabled' => true]);
        $this->tenantResolver->shouldReceive('getTenantId')->andReturn('42');
        $this->app->instance(TenantResolverInterface::class, $this->tenantResolver);

        $this->redis->shouldReceive('del')
            ->with('{tenant:42:test_models}:hash', '{tenant:42:test_models}:meta')
            ->andReturn(2);

        $service = $this->makeService();
        $service->callClearAll();
        $this->addToAssertionCount(1);
    }

    public function test_request_tenant_resolver_from_header(): void
    {
        $request = new Request([], [], [], [], [], ['HTTP_X_TENANT_ID' => 'acme-corp']);

        $resolver = new RequestTenantResolver(strategy: 'header', key: 'X-Tenant-ID');
        $this->app->instance(Request::class, $request);

        $this->assertSame('acme-corp', $resolver->getTenantId());
    }

    public function test_request_tenant_resolver_from_auth(): void
    {
        $user = Mockery::mock('Illuminate\Database\Eloquent\Model');
        $user->shouldReceive('getAttribute')->with('tenant_id')->andReturn(99);

        $request = new Request;
        $request->setUserResolver(fn () => $user);

        $resolver = new RequestTenantResolver(strategy: 'auth', key: 'tenant_id');
        $this->app->instance(Request::class, $request);

        $this->assertSame(99, $resolver->getTenantId());
    }

    public function test_request_tenant_resolver_from_subdomain(): void
    {
        $request = Request::create('https://acme.example.com/test');

        $resolver = new RequestTenantResolver(strategy: 'subdomain');
        $this->app->instance(Request::class, $request);

        $this->assertSame('acme', $resolver->getTenantId());
    }

    public function test_request_tenant_resolver_no_subdomain_for_bare_host(): void
    {
        $request = Request::create('https://localhost/test');

        $resolver = new RequestTenantResolver(strategy: 'subdomain');
        $this->app->instance(Request::class, $request);

        $this->assertNull($resolver->getTenantId());
    }

    public function test_request_tenant_resolver_no_session_when_unavailable(): void
    {
        $request = new Request;

        $resolver = new RequestTenantResolver(strategy: 'session');
        $this->app->instance(Request::class, $request);

        $this->assertNull($resolver->getTenantId());
    }

    public function test_not_instantiable_outside_http_context(): void
    {
        $resolver = new RequestTenantResolver;

        $this->assertNull($resolver->getTenantId());
    }

    protected function makeService(): RedisModelService
    {
        $matchStrategy = Mockery::mock('Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy');
        $matchStrategy->shouldReceive('normalize')->andReturnUsing(fn ($v) => $v);
        $matchStrategy->shouldReceive('matches')->andReturnUsing(fn ($a, $b) => $a === $b);

        return new class(connectionResolver: $this->connectionResolver, model_class: MultiTenantTestModel::class, indexes: ['role_id', 'status'], sorted: ['created_at'], ttl: 3600, matchStrategy: $matchStrategy) extends RedisModelService
        {
            public function callHashKey(): string
            {
                return $this->hashKey();
            }

            public function callMetaKey(): string
            {
                return $this->metaKey();
            }

            public function callClearAll(): void
            {
                $this->clearAll();
            }

            protected function collectKeysByPattern(string $pattern): array
            {
                return [$this->hashKey(), $this->metaKey()];
            }
        };
    }
}

class MultiTenantTestModel extends Model
{
    protected $table = 'test_models';

    public $timestamps = false;
}
