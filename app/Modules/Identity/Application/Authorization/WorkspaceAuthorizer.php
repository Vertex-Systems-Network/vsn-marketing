<?php

namespace App\Modules\Identity\Application\Authorization;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class WorkspaceAuthorizer
{
    public function allows(User $user, TenantContext $context, string $permission): bool
    {
        if (! PermissionCatalog::contains($permission) || (string) $user->getKey() !== $context->actorId) {
            return false;
        }

        return DB::table('workspace_memberships')
            ->join('workspace_membership_roles', 'workspace_membership_roles.workspace_membership_id', '=', 'workspace_memberships.id')
            ->join('workspace_roles', function ($join): void {
                $join->on('workspace_roles.id', '=', 'workspace_membership_roles.workspace_role_id')
                    ->on('workspace_roles.workspace_id', '=', 'workspace_memberships.workspace_id');
            })
            ->join('workspace_role_permissions', 'workspace_role_permissions.workspace_role_id', '=', 'workspace_roles.id')
            ->where('workspace_memberships.user_id', $user->getKey())
            ->where('workspace_memberships.workspace_id', $context->workspaceId)
            ->where('workspace_roles.workspace_id', $context->workspaceId)
            ->where('workspace_role_permissions.permission', $permission)
            ->exists();
    }
}
