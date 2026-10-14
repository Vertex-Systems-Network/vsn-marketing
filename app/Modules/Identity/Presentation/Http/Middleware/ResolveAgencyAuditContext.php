<?php

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Modules\Identity\Application\Authorization\AgencyDelegationAuthorizer;
use App\Modules\Identity\Application\Tenancy\TenantContextResolver;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Dedicated read-only agency audit scope; normal tenant routes stay membership-only. */
final readonly class ResolveAgencyAuditContext
{
    public function __construct(
        private TenantContextResolver $resolver,
        private TenantContextStore $store,
        private AgencyDelegationAuthorizer $delegation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }

        $workspaceId = $request->route('workspace');
        $agencyId = $request->route('agency');
        $context = $this->resolver->resolveDelegated(
            $user, is_string($workspaceId) ? $workspaceId : '', is_string($agencyId) ? $agencyId : '',
        );
        if (! $this->delegation->allows($user, (string) $agencyId, $context, PermissionCatalog::AUDIT_EXPORT)) {
            throw new AuthorizationException('Agency audit delegation denied.');
        }

        $this->store->set($context);
        $request->attributes->set('tenant_context', $context);
        try {
            return $next($request);
        } finally {
            $this->store->clear();
        }
    }
}
