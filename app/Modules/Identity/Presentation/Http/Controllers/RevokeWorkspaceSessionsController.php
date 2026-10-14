<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Sessions\WorkspaceSessionPolicy;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RevokeWorkspaceSessionsController
{
    public function __construct(
        private WorkspaceSessionPolicy $sessions,
        private TenantContextStore $tenant,
    ) {}

    public function __invoke(Request $request, string $workspace, string $user): Response
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException('Authentication is required.');
        }
        $this->sessions->revoke($this->tenant->require()->workspaceId, $user, (string) $actor->getKey());

        return response()->noContent();
    }
}
