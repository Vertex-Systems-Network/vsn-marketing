<?php

namespace App\Modules\Identity\Application\Authorization;

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WorkspaceRoleManager
{
    public function addMember(User $user, string $workspaceId): string
    {
        if (! DB::table('workspaces')->where('id', $workspaceId)->exists()) {
            throw new InvalidArgumentException('Workspace does not exist.');
        }

        $existing = DB::table('workspace_memberships')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->getKey())
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();

        DB::table('workspace_memberships')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'user_id' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function createRole(string $workspaceId, string $key, string $name): string
    {
        if (! DB::table('workspaces')->where('id', $workspaceId)->exists()) {
            throw new InvalidArgumentException('Workspace does not exist.');
        }

        $id = (string) Str::uuid();

        DB::table('workspace_roles')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'key' => $key,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function grantPermission(string $roleId, string $permission): void
    {
        if (! PermissionCatalog::contains($permission)) {
            throw new InvalidArgumentException("Unknown canonical permission: {$permission}");
        }

        if (! DB::table('workspace_roles')->where('id', $roleId)->exists()) {
            throw new InvalidArgumentException('Workspace role does not exist.');
        }

        DB::table('workspace_role_permissions')->updateOrInsert(
            ['workspace_role_id' => $roleId, 'permission' => $permission],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }

    public function assignRole(string $membershipId, string $roleId): void
    {
        $membership = DB::table('workspace_memberships')->where('id', $membershipId)->first();
        $role = DB::table('workspace_roles')->where('id', $roleId)->first();

        if ($membership === null || $role === null || $membership->workspace_id !== $role->workspace_id) {
            throw new AuthorizationException('Role assignment must remain within one workspace.');
        }

        DB::table('workspace_membership_roles')->updateOrInsert(
            ['workspace_membership_id' => $membershipId, 'workspace_role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );
    }
}
