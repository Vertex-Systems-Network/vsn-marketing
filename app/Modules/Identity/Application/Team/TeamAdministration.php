<?php

namespace App\Modules\Identity\Application\Team;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\DefaultRoles;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites and custom roles under least privilege (TASK-0105 AC-2/AC-3):
 * an administrator can only delegate permissions they hold, and privileged
 * permissions never travel through invites (they need GovernedRoleAssignment).
 */
final readonly class TeamAdministration
{
    public const INVITE_DAYS = 7;

    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private WorkspaceRoleManager $roles,
        private AuditRecorder $audit,
    ) {}

    /** @return array{id: string, token: string, expires_at: string} */
    public function invite(User $actor, TenantContext $context, string $email, string $roleId): array
    {
        $this->requireAdministrator($actor, $context);
        $permissions = $this->rolePermissions($context, $roleId);
        if (DefaultRoles::isPrivileged($permissions)) {
            throw new AuthorizationException('Privileged roles are granted through the governed role flow after the member joins.');
        }
        $this->requireHolds($actor, $context, $permissions);

        $email = Str::lower(trim($email));
        $alreadyMember = DB::table('workspace_memberships')->join('users', 'users.id', '=', 'workspace_memberships.user_id')
            ->where('workspace_memberships.workspace_id', $context->workspaceId)->where('users.email', $email)->exists();
        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => ['This person is already a member.']]);
        }

        $token = Str::random(48);
        $id = (string) Str::uuid();
        $expires = now()->addDays(self::INVITE_DAYS);
        DB::table('workspace_invites')->insert([
            'id' => $id, 'workspace_id' => $context->workspaceId, 'email' => $email,
            'workspace_role_id' => $roleId, 'token_hash' => hash('sha256', $token),
            'invited_by' => $actor->getKey(), 'expires_at' => $expires,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record($context->workspaceId, 'identity.invite.created', ['role_id' => $roleId],
            actorId: (string) $actor->getKey(), subjectType: 'workspace_invite', subjectId: $id);

        return ['id' => $id, 'token' => $token, 'expires_at' => $expires->toIso8601ZuluString()];
    }

    public function revoke(User $actor, TenantContext $context, string $inviteId): void
    {
        $this->requireAdministrator($actor, $context);
        $updated = DB::table('workspace_invites')->where('id', $inviteId)->where('workspace_id', $context->workspaceId)
            ->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($updated === 0) {
            throw new AuthorizationException('Invite is not open in this workspace.');
        }
        $this->audit->record($context->workspaceId, 'identity.invite.revoked', [],
            actorId: (string) $actor->getKey(), subjectType: 'workspace_invite', subjectId: $inviteId);
    }

    /** @return string workspace id joined */
    public function accept(User $user, string $token): string
    {
        return DB::transaction(function () use ($user, $token): string {
            $invite = DB::table('workspace_invites')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($invite === null || $invite->accepted_at !== null || $invite->revoked_at !== null
                || now()->greaterThan($invite->expires_at)
                || ! hash_equals((string) $invite->email, Str::lower((string) $user->getAttribute('email')))) {
                throw new AuthorizationException('Invite is invalid, expired or for another email address.');
            }
            // Re-check at acceptance: the role must still be non-privileged.
            $permissions = DB::table('workspace_role_permissions')->where('workspace_role_id', $invite->workspace_role_id)->pluck('permission')->all();
            if (DefaultRoles::isPrivileged($permissions)) {
                throw new AuthorizationException('Invite role became privileged; ask for a new invite.');
            }

            $membership = DB::table('workspace_memberships')->where('workspace_id', $invite->workspace_id)
                ->where('user_id', $user->getKey())->value('id');
            $membershipId = is_string($membership) ? $membership : $this->roles->addMember($user, (string) $invite->workspace_id);
            $this->roles->assignRole($membershipId, (string) $invite->workspace_role_id);
            DB::table('workspace_invites')->where('id', $invite->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
            $this->audit->record((string) $invite->workspace_id, 'identity.invite.accepted', ['role_id' => $invite->workspace_role_id],
                actorId: (string) $user->getKey(), subjectType: 'workspace_invite', subjectId: (string) $invite->id);

            return (string) $invite->workspace_id;
        });
    }

    /** @param list<string> $permissions */
    public function createRole(User $actor, TenantContext $context, string $key, string $name, array $permissions): string
    {
        $this->requireAdministrator($actor, $context);
        $permissions = array_values(array_unique($permissions));
        foreach ($permissions as $permission) {
            if (! PermissionCatalog::contains($permission)) {
                throw ValidationException::withMessages(['permissions' => ["Unknown permission: {$permission}"]]);
            }
        }
        $this->requireHolds($actor, $context, $permissions);
        if (array_key_exists($key, DefaultRoles::all())
            || DB::table('workspace_roles')->where('workspace_id', $context->workspaceId)->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => ['This role key is already used.']]);
        }

        return DB::transaction(function () use ($actor, $context, $key, $name, $permissions): string {
            $id = $this->roles->createRole($context->workspaceId, $key, $name);
            foreach ($permissions as $permission) {
                $this->roles->grantPermission($id, $permission);
            }
            $this->audit->record($context->workspaceId, 'identity.role.created', ['key' => $key, 'permissions' => $permissions],
                actorId: (string) $actor->getKey(), subjectType: 'workspace_role', subjectId: $id);

            return $id;
        });
    }

    private function requireAdministrator(User $actor, TenantContext $context): void
    {
        if (! $this->authorizer->allows($actor, $context, PermissionCatalog::IDENTITY_MANAGE)) {
            throw new AuthorizationException('Team administration denied.');
        }
    }

    /** @param list<string> $permissions */
    private function requireHolds(User $actor, TenantContext $context, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (! $this->authorizer->allows($actor, $context, $permission)) {
                throw new AuthorizationException('You cannot delegate a permission you do not hold.');
            }
        }
    }

    /** @return list<string> */
    private function rolePermissions(TenantContext $context, string $roleId): array
    {
        if (! DB::table('workspace_roles')->where('id', $roleId)->where('workspace_id', $context->workspaceId)->exists()) {
            throw new AuthorizationException('Role is not in this workspace.');
        }

        return DB::table('workspace_role_permissions')->where('workspace_role_id', $roleId)->pluck('permission')->map(fn ($p) => (string) $p)->all();
    }
}
