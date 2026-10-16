<?php

namespace App\Modules\Identity\Application\Federation;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** An opaque IdP subject is bound only after two independently authorized administrators review it. */
final readonly class GovernedSubjectBinding
{
    public function __construct(private WorkspaceAuthorizer $authorizer, private AuditRecorder $audit) {}

    public function propose(User $actor, TenantContext $context, string $userId, string $subject): string
    {
        $this->authorize($actor, $context);
        $issuer = $this->issuer($context);
        DB::transaction(fn () => $this->member($context, $userId));
        if ($subject === '' || strlen($subject) > 255 || trim($subject) !== $subject) {
            throw new AuthorizationException('A valid IdP subject is required.');
        }

        $id = (string) Str::uuid();
        DB::table('federation_binding_proposals')->insert([
            'id' => $id, 'workspace_id' => $context->workspaceId, 'user_id' => $userId,
            'issuer' => $issuer, 'subject' => $subject, 'proposed_by' => $actor->getKey(),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record($context->workspaceId, 'identity.federation.binding.proposed', [
            'proposal_id' => $id,
        ], actorId: (string) $actor->getKey(), subjectType: 'user', subjectId: $userId);

        return $id;
    }

    public function approve(User $approver, TenantContext $context, string $proposalId): void
    {
        $this->authorize($approver, $context);
        DB::transaction(function () use ($approver, $context, $proposalId): void {
            $proposal = $this->pending($context, $proposalId);
            if ($proposal->proposed_by === $approver->getKey()) {
                throw new AuthorizationException('Independent binding approval required.');
            }
            $this->verifyProposal($context, $proposal);
            DB::table('federation_binding_proposals')->where('id', $proposalId)->update([
                'status' => 'approved', 'approved_by' => $approver->getKey(), 'updated_at' => now(),
            ]);
            $this->audit->record($context->workspaceId, 'identity.federation.binding.approved', [
                'proposal_id' => $proposalId,
            ], actorId: (string) $approver->getKey(), subjectType: 'user', subjectId: $proposal->user_id);
        });
    }

    public function apply(User $actor, TenantContext $context, string $proposalId): void
    {
        $this->authorize($actor, $context);
        DB::transaction(function () use ($actor, $context, $proposalId): void {
            $proposal = DB::table('federation_binding_proposals')->where('id', $proposalId)
                ->where('workspace_id', $context->workspaceId)->lockForUpdate()->first();
            if ($proposal === null || $proposal->status !== 'approved' || $proposal->proposed_by !== $actor->getKey()
                || $proposal->approved_by === null || $proposal->approved_by === $actor->getKey()
                || ! $this->approverStillAuthorized($context, (string) $proposal->approved_by)) {
                throw new AuthorizationException('Approved binding proposal required.');
            }
            $this->verifyProposal($context, $proposal);
            if (DB::table('federation_subject_bindings')->where('workspace_id', $context->workspaceId)
                ->where('issuer', $proposal->issuer)->where(function ($query) use ($proposal): void {
                    $query->where('subject', $proposal->subject)->orWhere('user_id', $proposal->user_id);
                })->exists()) {
                throw new AuthorizationException('IdP subject is already bound.');
            }
            DB::table('federation_subject_bindings')->insert([
                'id' => (string) Str::uuid(), 'workspace_id' => $context->workspaceId,
                'user_id' => $proposal->user_id, 'issuer' => $proposal->issuer,
                'subject' => $proposal->subject, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('federation_binding_proposals')->where('id', $proposalId)->update([
                'status' => 'applied', 'updated_at' => now(),
            ]);
            $this->audit->record($context->workspaceId, 'identity.federation.binding.applied', [
                'proposal_id' => $proposalId,
            ], actorId: (string) $actor->getKey(), subjectType: 'user', subjectId: $proposal->user_id);
        });
    }

    private function pending(TenantContext $context, string $id): object
    {
        $proposal = DB::table('federation_binding_proposals')->where('id', $id)
            ->where('workspace_id', $context->workspaceId)->lockForUpdate()->first();
        if ($proposal === null || $proposal->status !== 'pending') {
            throw new AuthorizationException('Pending binding proposal required.');
        }

        return $proposal;
    }

    private function verifyProposal(TenantContext $context, object $proposal): void
    {
        if (! hash_equals($this->issuer($context), $proposal->issuer)) {
            throw new AuthorizationException('IdP issuer changed.');
        }
        $this->member($context, $proposal->user_id);
    }

    private function member(TenantContext $context, string $userId): void
    {
        // Match the deactivation lock order before reading membership.
        if (DB::table('users')->where('id', $userId)->lockForUpdate()->first() === null) {
            throw new AuthorizationException('Active workspace membership required.');
        }
        $member = DB::table('workspace_memberships')->where('workspace_id', $context->workspaceId)
            ->where('user_id', $userId)->lockForUpdate()->first();
        if ($member === null) {
            throw new AuthorizationException('Active workspace membership required.');
        }
    }

    private function issuer(TenantContext $context): string
    {
        $issuer = config('enterprise.oidc.issuer');
        if (! is_string($issuer) || $issuer === '' || config('enterprise.oidc.workspace_id') !== $context->workspaceId) {
            throw new AuthorizationException('Workspace IdP configuration required.');
        }

        return $issuer;
    }

    private function authorize(User $actor, TenantContext $context): void
    {
        if (! $this->authorizer->allows($actor, $context, PermissionCatalog::IDENTITY_MANAGE)) {
            throw new AuthorizationException('Identity administration denied.');
        }
    }

    private function approverStillAuthorized(TenantContext $context, string $id): bool
    {
        $user = User::query()->find($id);

        return $user instanceof User && $this->authorizer->allows($user, new TenantContext(
            $context->organizationId, $context->workspaceId, null, $id,
        ), PermissionCatalog::IDENTITY_MANAGE);
    }
}
