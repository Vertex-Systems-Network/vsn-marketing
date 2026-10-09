<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Infrastructure\DatabaseBoundedAutonomyApprovalSource;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Last-boundary OFFLINE review gate for a previously reserved, exact plan.
 *
 * No provider adapter is invoked. All success states are review evidence,
 * never external execution authorization. A real send would need its own
 * adapter-specific durable outcome, permission, consent and suppression gates.
 */
final readonly class BoundedAutonomyOfflineFinalGate
{
    public function __construct(private WorkspaceAuthorizer $authorizer) {}

    public function inspect(
        TenantContext $scope,
        array $preview,
        array $binding,
        DateTimeImmutable $at,
    ): array {
        if (($preview['status'] ?? null) !== 'preview_ready'
            || ($preview['tenant'] ?? null) !== $scope->toArray()
            || ($preview['execution_authorized'] ?? null) !== false
            || ($preview['stages']['execute'] ?? null) !== 'disabled'
            || ! is_string($preview['run_id'] ?? null)
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $preview['run_id']) !== 1
            || ! is_string($preview['snapshot_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $preview['snapshot_sha256']) !== 1) {
            throw new InvalidArgumentException('Untrusted final-gate plan rejected.');
        }

        return DB::transaction(function () use ($scope, $preview, $binding, $at): array {
            // Follow the same lock order as admission and stop reconciliation.
            $global = DB::table('ai_autonomy_global_stops')
                ->where('id', 'global')->lockForUpdate()->first();
            if ($global === null || (int) $global->stopped !== 0) {
                return $this->hold($preview, 'global_emergency_stop');
            }

            $period = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
            $quota = DB::table('ai_autonomy_workspace_quotas')
                ->where('workspace_id', $scope->workspaceId)->where('period_utc', $period)
                ->lockForUpdate()->first();
            if ($quota === null || (int) $quota->workspace_stopped !== 0) {
                return $this->hold($preview, 'workspace_emergency_stop_or_unconfigured');
            }
            if ($quota->policy_version !== ($preview['policy_version'] ?? null)
                || (new DateTimeImmutable($quota->policy_expires_at, new DateTimeZone('UTC')))
                    ->getTimestamp() <= $at->getTimestamp()) {
                return $this->hold($preview, 'stale_or_changed_resource_policy');
            }
            $reservation = DB::table('ai_autonomy_offline_reservations')
                ->where('workspace_id', $scope->workspaceId)
                ->where('run_id', $preview['run_id'])->lockForUpdate()->first();
            if ($reservation === null || $reservation->status !== 'offline_reserved') {
                return $this->hold($preview, 'current_offline_reservation_unavailable');
            }
            if ($reservation->actor_id !== $scope->actorId
                || $reservation->brand_id !== $scope->brandId
                || $reservation->snapshot_sha256 !== $preview['snapshot_sha256']
                || $reservation->policy_version !== $preview['policy_version']) {
                throw new InvalidArgumentException('Offline final-gate reservation scope mismatch.');
            }
            if ((int) $quota->used_actions > (int) $quota->max_actions
                || (int) $quota->used_tokens > (int) $quota->max_tokens
                || (int) $quota->used_volume > (int) $quota->max_volume
                || (int) $quota->used_attempts > (int) $quota->max_attempts
                || (int) $quota->reserved_cost_minor + (int) $quota->spent_cost_minor
                    > (int) $quota->max_cost_minor
                || (int) $reservation->cost_minor > ($binding['max_cost_minor'] ?? -1)
                || (int) $reservation->volume > ($binding['max_volume'] ?? -1)) {
                return $this->hold($preview, 'resource_or_approval_ceiling_changed');
            }

            $approval = (new BoundedAutonomyOfflineApprovalReview(
                new DatabaseBoundedAutonomyApprovalSource($this->authorizer),
            ))->inspect($scope, $preview, $binding, $at);
            if ($approval['status'] !== 'approval_matched_offline') {
                return $this->hold($preview, $approval['reason_code']);
            }

            return [
                'status' => 'offline_final_review_ready',
                'reason_code' => 'external_authority_still_disabled',
                'run_id' => $preview['run_id'],
                'decision_id' => $approval['decision_id'],
                'snapshot_sha256' => $preview['snapshot_sha256'],
                'execution_authorized' => false,
                'promotion_authorized' => false,
                'external_outcome_verified' => false,
            ];
        }, 3);
    }

    private function hold(array $preview, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => $preview['run_id'],
            'decision_id' => null,
            'snapshot_sha256' => $preview['snapshot_sha256'],
            'execution_authorized' => false,
            'promotion_authorized' => false,
            'external_outcome_verified' => false,
        ];
    }
}
