<?php

namespace App\Modules\Identity\Application\Authorization;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Role grants need a separate authenticated administrator's durable receipt. */
final readonly class GovernedRoleAssignment
{
    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private WorkspaceRoleManager $roles,
        private AuditRecorder $audit,
    ) {}

    public function propose(User $actor, TenantContext $context, string $membershipId, string $roleId): string
    {
        $this->authorize($actor, $context);
        $this->verifyRoleAndMember($actor, $context, $membershipId, $roleId);
        $id = (string) Str::uuid();
        DB::table('workspace_role_assignment_proposals')->insert([
            'id' => $id, 'workspace_id' => $context->workspaceId, 'membership_id' => $membershipId,
            'role_id' => $roleId, 'proposed_by' => $actor->getKey(),
            'permissions_fingerprint' => $this->permissionsFingerprint($roleId),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function approve(User $approver, TenantContext $context, string $proposalId): void
    {
        $this->authorize($approver, $context);
        DB::transaction(function () use ($approver, $context, $proposalId): void {
            $proposal = DB::table('workspace_role_assignment_proposals')->where('id', $proposalId)
                ->where('workspace_id', $context->workspaceId)->lockForUpdate()->first();
            if ($proposal === null || $proposal->status !== 'pending' || $proposal->proposed_by === $approver->getKey()
                || ! hash_equals($proposal->permissions_fingerprint, $this->permissionsFingerprint($proposal->role_id))) {
                throw new AuthorizationException('Independent role approval denied.');
            }

            DB::table('workspace_role_assignment_proposals')->where('id', $proposalId)->update([
                'status' => 'approved', 'approved_by' => $approver->getKey(), 'updated_at' => now(),
            ]);
            $this->audit->record($context->workspaceId, 'identity.role.approved', [
                'proposal_id' => $proposalId,
            ], actorId: (string) $approver->getKey());
        });
    }

    public function apply(User $actor, TenantContext $context, string $proposalId): void
    {
        $this->authorize($actor, $context);
        DB::transaction(function () use ($actor, $context, $proposalId): void {
            $proposal = DB::table('workspace_role_assignment_proposals')->where('id', $proposalId)
                ->where('workspace_id', $context->workspaceId)->lockForUpdate()->first();
            if ($proposal === null || $proposal->status !== 'approved' || $proposal->proposed_by !== $actor->getKey()
                || $proposal->approved_by === null || $proposal->approved_by === $actor->getKey()
                || ! $this->approverStillAuthorized($context, (string) $proposal->approved_by)
                || ! hash_equals($proposal->permissions_fingerprint, $this->permissionsFingerprint($proposal->role_id))) {
                throw new AuthorizationException('Approved role proposal required.');
            }

            $target = $this->verifyRoleAndMember($actor, $context, $proposal->membership_id, $proposal->role_id);
            $this->roles->assignRole($proposal->membership_id, $proposal->role_id);
            DB::table('workspace_role_assignment_proposals')->where('id', $proposalId)->update([
                'status' => 'applied', 'updated_at' => now(),
            ]);
            $this->audit->record($context->workspaceId, 'identity.role.assigned', [
                'proposal_id' => $proposalId, 'role_id' => $proposal->role_id,
            ], actorId: (string) $actor->getKey(), subjectType: 'user', subjectId: (string) $target->user_id);
        });
    }

    private function authorize(User $user, TenantContext $context): void
    {
        if (! $this->authorizer->allows($user, $context, PermissionCatalog::IDENTITY_MANAGE)) {
            throw new AuthorizationException('Role administration denied.');
        }
    }

    private function approverStillAuthorized(TenantContext $context, string $approverId): bool
    {
        $approver = User::query()->find($approverId);

        return $approver instanceof User && $this->authorizer->allows($approver, new TenantContext(
            $context->organizationId, $context->workspaceId, null, $approverId,
        ), PermissionCatalog::IDENTITY_MANAGE);
    }

    private function verifyRoleAndMember(User $actor, TenantContext $context, string $membershipId, string $roleId): object
    {
        $target = DB::table('workspace_memberships')->where('id', $membershipId)
            ->where('workspace_id', $context->workspaceId)->lockForUpdate()->first();
        $role = DB::table('workspace_roles')->where('id', $roleId)
            ->where('workspace_id', $context->workspaceId)->first();
        if ($target === null || $role === null) {
            throw new AuthorizationException('Role and member must share the administered workspace.');
        }

        foreach (DB::table('workspace_role_permissions')->where('workspace_role_id', $roleId)->pluck('permission') as $permission) {
            if (! $this->authorizer->allows($actor, $context, (string) $permission)) {
                throw new AuthorizationException('Actor cannot delegate a permission they do not hold.');
            }
        }

        return $target;
    }

    private function permissionsFingerprint(string $roleId): string
    {
        return hash('sha256', DB::table('workspace_role_permissions')->where('workspace_role_id', $roleId)
            ->orderBy('permission')->pluck('permission')->implode("\0"));
    }
}
