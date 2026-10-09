<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryHumanDecisionSource;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only latest-decision source with current *independent* human RBAC.
 * No session-bound decision writer or side-effect route is installed here.
 * A historical grant cannot substitute for a currently authorized approver.
 */
final readonly class DatabaseBoundedAutonomyCanaryHumanDecisionSource implements BoundedAutonomyCanaryHumanDecisionSource
{
    public function __construct(private WorkspaceAuthorizer $authorizer) {}

    public function latest(TenantContext $scope, string $experimentId, DateTimeImmutable $at): ?array
    {
        if ($scope->actorId === '' || $scope->workspaceId === ''
            || preg_match('/^[a-f0-9-]{36}$/D', $experimentId) !== 1) {
            return null;
        }

        if (! DB::table('workspaces')->where('id', $scope->workspaceId)
            ->where('organization_id', $scope->organizationId)->exists()) {
            return null;
        }

        $experiment = DB::table('experiments')
            ->where('id', $experimentId)->where('workspace_id', $scope->workspaceId)
            ->where('brand_id', $scope->brandId)->first();
        if ($experiment === null || $experiment->status !== 'active'
            || $experiment->approved_by_actor_id === null
            || $experiment->approved_by_actor_id === $experiment->created_by_actor_id) {
            return null;
        }

        $row = DB::table('ai_autonomy_canary_human_decisions')
            ->where('workspace_id', $scope->workspaceId)
            ->where('brand_id', $scope->brandId)
            ->where('experiment_id', $experimentId)
            ->orderByDesc('sequence')->first();
        if ($row === null || (int) $row->human_session_verified !== 1
            || ! is_string($row->session_proof_sha256)
            || preg_match('/^[a-f0-9]{64}$/D', $row->session_proof_sha256) !== 1
            || $row->plan_sha256 !== $experiment->plan_hash
            || $row->policy_version !== 'v1'
            || (int) $row->observed_at_unix > $at->getTimestamp()
            || (int) $row->observed_at_unix < $at->getTimestamp() - 300
            || (int) $row->expires_at_unix <= $at->getTimestamp()
            || (int) $row->expires_at_unix > $at->getTimestamp() + 3600) {
            return null;
        }

        $approver = User::query()->find($row->deciding_actor_id);
        if ($approver === null || (string) $approver->getKey() === $scope->actorId) {
            return null;
        }
        $approverScope = new TenantContext(
            $scope->organizationId, $scope->workspaceId, $scope->brandId,
            (string) $approver->getKey(),
        );
        if (! $this->authorizer->allows($approver, $approverScope, PermissionCatalog::AI_APPROVE)
            || ! $this->authorizer->allows($approver, $approverScope, PermissionCatalog::CAMPAIGN_APPROVE)) {
            return null;
        }

        return [
            'tenant' => $scope->toArray(),
            'experiment_id' => $experimentId,
            'plan_sha256' => $row->plan_sha256,
            'analysis_sha256' => $row->analysis_sha256,
            'cohort_receipt_id' => $row->cohort_receipt_id,
            'outcome_manifest_sha256' => $row->outcome_manifest_sha256,
            'decision_id' => $row->decision_id,
            'deciding_actor_id' => (string) $approver->getKey(),
            'outcome' => $row->outcome,
            'policy_version' => $row->policy_version,
            'authenticated_human' => true,
            'current_permission_verified' => true,
            'observed_at_unix' => (int) $row->observed_at_unix,
            'expires_at_unix' => (int) $row->expires_at_unix,
        ];
    }
}
