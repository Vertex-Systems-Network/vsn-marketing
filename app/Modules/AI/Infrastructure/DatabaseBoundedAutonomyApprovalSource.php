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
 * Read-only current-decision authority source for OFFLINE proposal review.
 *
 * Resolves the latest immutable decision and rechecks the approver's present
 * workspace membership/AI_APPROVE authority. It has no approval writer,
 * no provider credentials and cannot authorize actions or promotion.
 */
final readonly class DatabaseBoundedAutonomyApprovalSource implements BoundedAutonomyApprovalSource
{
    public function __construct(private WorkspaceAuthorizer $authorizer) {}

    public function latest(TenantContext $scope, string $runId, DateTimeImmutable $at): ?array
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId) !== 1) {
            return null;
        }

        $belongs = DB::table('workspaces')
            ->where('id', $scope->workspaceId)
            ->where('organization_id', $scope->organizationId)->exists();

        if (! $belongs) {
            return null;
        }

        // Do not filter by supplied brand before ordering. A decision with
        // changed tenant/brand remains the latest and must fail exact binding;
        // an older valid approval cannot be resurrected by a brand mismatch.
        $record = DB::table('ai_autonomy_approval_decisions')
            ->where('workspace_id', $scope->workspaceId)
            ->where('run_id', $runId)
            ->orderByDesc('approved_at_unix')
            ->orderByDesc('id')->first();

        if ($record === null) {
            return null;
        }

        $approver = User::query()->find($record->approver_id);
        if (! $approver instanceof User) {
            return null;
        }

        $approverScope = new TenantContext(
            $scope->organizationId, $scope->workspaceId, null, (string) $record->approver_id,
        );
        if (! $this->authorizer->allows($approver, $approverScope, PermissionCatalog::AI_APPROVE)) {
            return null;
        }

        return [
            'decision_id' => (string) $record->id,
            'workspace_id' => (string) $record->workspace_id,
            'brand_id' => $record->brand_id,
            'run_id' => (string) $record->run_id,
            'snapshot_sha256' => (string) $record->snapshot_sha256,
            'policy_version' => (string) $record->policy_version,
            'audience_sha256' => (string) $record->audience_sha256,
            'content_sha256' => (string) $record->content_sha256,
            'destination_sha256' => (string) $record->destination_sha256,
            'max_cost_minor' => (int) $record->max_cost_minor,
            'max_volume' => (int) $record->max_volume,
            'not_before_unix' => (int) $record->not_before_unix,
            'expires_at_unix' => (int) $record->expires_at_unix,
            'approved_at_unix' => (int) $record->approved_at_unix,
            'approver_id' => (string) $record->approver_id,
            'outcome' => (string) $record->outcome,
        ];
    }
}
