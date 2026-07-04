<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Contracts;

/**
 * Interface for resolving tenant ID in multi-tenant applications.
 *
 * Implement this interface to provide tenant context for cache key namespacing.
 */
interface TenantResolverInterface
{
    /**
     * Get the current tenant identifier.
     *
     * @return string|int|null Tenant ID or null if not in tenant context
     */
    public function getTenantId(): string|int|null;
}
