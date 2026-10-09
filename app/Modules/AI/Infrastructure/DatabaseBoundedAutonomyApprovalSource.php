<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyApprovalSource;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only verification of the last durable human approval decision.
 *
 * The authoritative approver permission is rechecked from CURRENT workspace
 * role membership on every read; stored claims of authority are not trusted.
 * A newer revocation or rejection supersedes prior approvals. Absent grants
 * or decisions always deny. No execution route or approval writer is exposed.
 */
final readonly class DatabaseBoundedAutonomyApprovalSource implements BoundedAutonomyApprovalSource
{
    public function __construct(private WorkspaceAuthorizer $authorizer) {}

    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array
    {
        if ($scope->workspaceId === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId) !== 1) {
            return null;
        }

        $row = DB::table('ai_autonomy_offline_approval_decisions')
            ->where('workspace_id', $scope->workspaceId)
            ->where('run_id', $runId)
            ->orderByDesc('sequence')
            ->first();

        if ($row === null) {
            return null;
        }

        $approver = User::query()->find($row->approver_id);
        if ($approver === null || (string) $approver->getKey() === $scope->actorId) {
            return null;
        }

        $approverScope = new TenantContext(
            $scope->organizationId, $scope->workspaceId, $scope->brandId,
            (string) $approver->getKey(),
        );
        if (! $this->authorizer->allows($approver, $approverScope, PermissionCatalog::AI_APPROVE)) {
            return null;
        }

        // The evaluator independently validates immutable scope, exact
        // fingerprints, expiry, approval outcome and requestor independence.
        return [
            'decision_id' => $row->decision_id,
            'workspace_id' => $row->workspace_id,
            'brand_id' => $row->brand_id,
            'run_id' => $row->run_id,
            'snapshot_sha256' => $row->snapshot_sha256,
            'policy_version' => $row->policy_version,
            'audience_sha256' => $row->audience_sha256,
            'content_sha256' => $row->content_sha256,
            'destination_sha256' => $row->destination_sha256,
            'max_cost_minor' => (int) $row->max_cost_minor,
            'max_volume' => (int) $row->max_volume,
            'not_before_unix' => (int) $row->not_before_unix,
            'expires_at_unix' => (int) $row->expires_at_unix,
            'approved_at_unix' => (int) $row->approved_at_unix,
            'approver_id' => $row->approver_id,
            'outcome' => $row->outcome,
        ];
    }
}
