<?php

namespace App\Modules\Identity\Application\Tenancy;

use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class TenantContextResolver
{
    public function resolveDelegated(User $user, string $workspaceId, string $agencyWorkspaceId, ?string $brandId = null): TenantContext
    {
        if ($workspaceId === '' || $agencyWorkspaceId === '' || $workspaceId === $agencyWorkspaceId
            || ! DB::table('agency_client_delegations')->where('client_workspace_id', $workspaceId)
                ->where('agency_workspace_id', $agencyWorkspaceId)->where('user_id', $user->getKey())->exists()) {
            throw new AuthorizationException('Delegated workspace access denied.');
        }

        $workspace = DB::table('workspaces')->where('id', $workspaceId)->first(['id', 'organization_id']);
        if ($workspace === null || ($brandId !== null && ! DB::table('brands')
            ->where('id', $brandId)->where('workspace_id', $workspaceId)->exists())) {
            throw new AuthorizationException('Delegated workspace or brand access denied.');
        }

        return new TenantContext(
            (string) $workspace->organization_id, (string) $workspace->id, $brandId, (string) $user->getKey(),
        );
    }

    public function resolve(User $user, string $workspaceId, ?string $brandId = null): TenantContext
    {
        if ($workspaceId === '') {
            throw new AuthorizationException('Workspace context is required.');
        }

        $workspace = DB::table('workspaces')
            ->select(['workspaces.id', 'workspaces.organization_id'])
            ->join('workspace_memberships', function ($join) use ($user): void {
                $join->on('workspace_memberships.workspace_id', '=', 'workspaces.id')
                    ->where('workspace_memberships.user_id', '=', $user->getKey());
            })
            ->where('workspaces.id', $workspaceId)
            ->first();

        if ($workspace === null) {
            throw new AuthorizationException('Workspace access denied.');
        }

        if ($brandId !== null) {
            $brandExists = DB::table('brands')
                ->where('id', $brandId)
                ->where('workspace_id', $workspaceId)
                ->exists();

            if (! $brandExists) {
                throw new AuthorizationException('Brand access denied.');
            }
        }

        return new TenantContext(
            organizationId: (string) $workspace->organization_id,
            workspaceId: (string) $workspace->id,
            brandId: $brandId,
            actorId: (string) $user->getKey(),
        );
    }
}
