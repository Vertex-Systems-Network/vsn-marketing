<?php

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Modules\Identity\Application\Tenancy\TenantContextResolver;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Identity\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContextResolver $resolver,
        private readonly TenantContextStore $store,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Authentication is required before tenant resolution.');
        }

        $workspaceId = $this->routeIdentifier($request->route('workspace'))
            ?? $request->header('X-Workspace-Id');
        $brandId = $this->routeIdentifier($request->route('brand'))
            ?? $request->header('X-Brand-Id');

        $context = $this->resolver->resolve(
            user: $user,
            workspaceId: is_string($workspaceId) ? $workspaceId : '',
            brandId: is_string($brandId) && $brandId !== '' ? $brandId : null,
        );

        $this->store->set($context);
        $request->attributes->set('tenant_context', $context);

        try {
            return $next($request);
        } finally {
            $this->store->clear();
        }
    }

    private function routeIdentifier(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'getKey')) {
            return (string) $value->getKey();
        }

        return null;
    }
}
