<?php

declare(strict_types=1);

namespace Sm_mE\RedisModelCache\Support\TenantResolvers;

use Illuminate\Http\Request;
use Sm_mE\RedisModelCache\Contracts\TenantResolverInterface;

/**
 * Resolve the current tenant ID from the HTTP request context.
 *
 * Supports multiple resolution strategies:
 * - 'header': Read from a request header (default: X-Tenant-ID)
 * - 'subdomain': Extract the first subdomain segment
 * - 'auth': Read from the authenticated user's attribute (default: tenant_id)
 * - 'session': Read from the session key (default: tenant_id)
 */
class RequestTenantResolver implements TenantResolverInterface
{
    /**
     * @param  string  $strategy  Resolution strategy: 'header', 'subdomain', 'auth', 'session'
     * @param  string  $key  Header name, session key, or user attribute name
     */
    public function __construct(
        protected string $strategy = 'header',
        protected string $key = 'X-Tenant-ID',
    ) {}

    public function getTenantId(): string|int|null
    {
        try {
            $request = app(Request::class);
        } catch (\Throwable) {
            return null;
        }

        return match ($this->strategy) {
            'subdomain' => $this->resolveFromSubdomain($request),
            'auth' => $this->resolveFromAuth($request),
            'session' => $this->resolveFromSession($request),
            default => $this->resolveFromHeader($request),
        };
    }

    protected function resolveFromHeader(Request $request): string|int|null
    {
        $value = $request->header($this->key);

        return $value !== null && $value !== '' ? $value : null;
    }

    protected function resolveFromSubdomain(Request $request): ?string
    {
        $host = $request->getHost();
        $parts = explode('.', $host);

        // First segment is the subdomain (e.g. "acme" from "acme.example.com")
        // Skip if it's "www" or a bare hostname (no subdomain)
        if (count($parts) < 3 || $parts[0] === 'www') {
            return null;
        }

        return $parts[0];
    }

    protected function resolveFromAuth(Request $request): string|int|null
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return $user->getAttribute($this->key);
    }

    protected function resolveFromSession(Request $request): string|int|null
    {
        if (! $request->hasSession()) {
            return null;
        }

        return $request->session()->get($this->key);
    }
}
