<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Conservatively reconcile OFFLINE reservations when global/workspace
 * emergency-stop authority changes. No provider outcome is inferred.
 */
final class DatabaseBoundedAutonomyStopReconciliation
{
    public function reconcile(TenantContext $scope, string $runId, string $snapshotSha256): array
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotSha256) !== 1) {
            throw new InvalidArgumentException('Invalid offline run or evidence fingerprint.');
        }

        return DB::transaction(function () use ($scope, $runId, $snapshotSha256): array {
            // Scope fields alone are not evidence that the claimed
            // organization owns the workspace being reconciled.
            if (! DB::table('workspaces')->where('id', $scope->workspaceId)
                ->where('organization_id', $scope->organizationId)->exists()) {
                throw new InvalidArgumentException('Foreign organization workspace rejected.');
            }

            // Exact lock order matches reservation admission.
            $global = DB::table('ai_autonomy_global_stops')->where('id', 'global')
                ->lockForUpdate()->first();
            $period = DB::table('ai_autonomy_offline_reservations')
                ->where('workspace_id', $scope->workspaceId)
                ->where('run_id', $runId)->value('period_utc');
            if ($period === null) {
                return $this->result($runId, $snapshotSha256, 'reservation_missing');
            }
            $quota = DB::table('ai_autonomy_workspace_quotas')
                ->where('workspace_id', $scope->workspaceId)
                ->where('period_utc', $period)->lockForUpdate()->first();
            if ($quota === null) {
                return $this->result($runId, $snapshotSha256, 'quota_authority_missing');
            }
            $record = DB::table('ai_autonomy_offline_reservations')
                ->where('workspace_id', $scope->workspaceId)
                ->where('run_id', $runId)->lockForUpdate()->first();
            if ($record === null) {
                return $this->result($runId, $snapshotSha256, 'reservation_missing');
            }
            if ($record->actor_id !== $scope->actorId
                || $record->brand_id !== $scope->brandId
                || $record->snapshot_sha256 !== $snapshotSha256) {
                throw new InvalidArgumentException('Offline reservation actor or evidence mismatch.');
            }

            $stopped = $global === null || (int) $global->stopped !== 0
                || (int) $quota->workspace_stopped !== 0;
            if (! $stopped) {
                return $this->result($runId, $snapshotSha256, 'stop_not_active');
            }
            if ($record->status === 'offline_reserved') {
                DB::table('ai_autonomy_offline_reservations')
                    ->where('workspace_id', $scope->workspaceId)
                    ->where('run_id', $runId)->where('status', 'offline_reserved')
                    ->update(['status' => 'held_by_emergency_stop', 'updated_at' => now()]);

                return $this->result($runId, $snapshotSha256, 'stop_recorded_offline');
            }
            if ($record->status === 'held_by_emergency_stop') {
                return $this->result($runId, $snapshotSha256, 'already_held');
            }

            // An unknown prior external outcome is neither refunded nor
            // classified as a successful stop: hold for independent review.
            return $this->result($runId, $snapshotSha256, 'external_outcome_unverified');
        }, 3);
    }

    private function result(string $runId, string $snapshotSha256, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => $runId,
            'snapshot_sha256' => $snapshotSha256,
            'resource_counters_preserved' => true,
            'external_outcome_verified' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
