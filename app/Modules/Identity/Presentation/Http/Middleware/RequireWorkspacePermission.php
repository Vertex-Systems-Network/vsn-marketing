<?php

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Identity\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireWorkspacePermission
{
    public function __construct(
        private readonly TenantContextStore $store,
        private readonly WorkspaceAuthorizer $authorizer,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }

        if (! $this->authorizer->allows($user, $this->store->require(), $permission)) {
            throw new AuthorizationException('Workspace permission denied.');
        }

        return $next($request);
    }
}
