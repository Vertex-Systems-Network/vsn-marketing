<?php

namespace App\Modules\Identity\Application\Tenancy;

use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class TenantContextResolver
{
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
