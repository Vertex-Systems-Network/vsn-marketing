<?php

namespace App\Modules\Identity\Application\Billing;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable separate-actor proposal and approval; no payment is enabled. */
final readonly class ConfigureMeterEntitlement
{
    public function __construct(private WorkspaceAuthorizer $authorizer, private AuditRecorder $audit) {}

    public function propose(User $actor, TenantContext $context, string $meter, int $limitUnits, bool $enabled): string
    {
        $this->authorize($actor, $context);
        if ($meter === '' || strlen($meter) > 80 || $limitUnits < 0
            || ! preg_match('/^[a-z][a-z0-9_]*$/D', $meter)) {
            throw new AuthorizationException('Invalid entitlement proposal.');
        }

        $id = (string) Str::uuid();
        DB::table('workspace_meter_change_proposals')->insert([
            'id' => $id, 'workspace_id' => $context->workspaceId, 'proposed_by' => $actor->getKey(),
            'meter' => $meter, 'limit_units' => $limitUnits, 'enabled' => $enabled,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function approve(User $approver, TenantContext $context, string $proposalId): void
    {
        $this->authorize($approver, $context);
        DB::transaction(function () use ($approver, $context, $proposalId): void {
            $proposal = DB::table('workspace_meter_change_proposals')->where('id', $proposalId)
                ->where('workspace_id', $context->workspaceId)->lockForUpdate()->first();
            if ($proposal === null || $proposal->status !== 'pending' || $proposal->proposed_by === $approver->getKey()) {
                throw new AuthorizationException('Independent approval denied.');
            }

            DB::table('workspace_meter_change_proposals')->where('id', $proposalId)->update([
                'status' => 'approved', 'approved_by' => $approver->getKey(), 'updated_at' => now(),
            ]);
            $this->audit->record($context->workspaceId, 'billing.entitlement.approved', [
                'proposal_id' => $proposalId,
            ], actorId: (string) $approver->getKey());
        });
    }

    public function apply(User $actor, TenantContext $context, string $proposalId): void
    {
        $this->authorize($actor, $context);
        DB::transaction(function () use ($actor, $context, $proposalId): void {
            $proposal = DB::table('workspace_meter_change_proposals')->where('id', $proposalId)
                ->where('workspace_id', $context->workspaceId)->lockForUpdate()->first();
            if ($proposal === null || $proposal->status !== 'approved' || $proposal->proposed_by !== $actor->getKey()
                || $proposal->approved_by === null || $proposal->approved_by === $actor->getKey()
                || ! $this->approverStillAuthorized($context, (string) $proposal->approved_by)) {
                throw new AuthorizationException('Approved entitlement proposal required.');
            }

            $existing = DB::table('workspace_meter_entitlements')->where('workspace_id', $context->workspaceId)
                ->where('meter', $proposal->meter)->lockForUpdate()->first();
            if ($existing !== null && (int) $proposal->limit_units < (int) $existing->used_units) {
                throw new AuthorizationException('Entitlement cannot fall below consumed usage.');
            }

            DB::table('workspace_meter_entitlements')->updateOrInsert(
                ['workspace_id' => $context->workspaceId, 'meter' => $proposal->meter],
                ['limit_units' => $proposal->limit_units, 'used_units' => $existing->used_units ?? 0,
                    'enabled' => $proposal->enabled, 'created_at' => $existing->created_at ?? now(), 'updated_at' => now()],
            );
            DB::table('workspace_meter_change_proposals')->where('id', $proposalId)->update([
                'status' => 'applied', 'updated_at' => now(),
            ]);
            $this->audit->record($context->workspaceId, 'billing.entitlement.configured', [
                'proposal_id' => $proposalId, 'meter' => $proposal->meter,
                'limit_units' => (int) $proposal->limit_units, 'enabled' => (bool) $proposal->enabled,
            ], actorId: (string) $actor->getKey());
        });
    }

    private function authorize(User $user, TenantContext $context): void
    {
        if (! $this->authorizer->allows($user, $context, PermissionCatalog::BILLING_MANAGE)) {
            throw new AuthorizationException('Billing administration denied.');
        }
    }

    private function approverStillAuthorized(TenantContext $context, string $approverId): bool
    {
        $approver = User::query()->find($approverId);

        return $approver instanceof User && $this->authorizer->allows($approver, new TenantContext(
            $context->organizationId, $context->workspaceId, null, $approverId,
        ), PermissionCatalog::BILLING_MANAGE);
    }
}
