<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Application\BoundedAutonomyOfflineSafetyGate;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Atomic OFFLINE-ONLY quota reservation under global and workspace row locks.
 * No queue, provider, delivery, campaign promotion or external effect exists.
 * All reservations remain held until separate settlement/reconciliation work.
 */
final class DatabaseBoundedAutonomyOfflineReservation
{
    public function reserve(TenantContext $scope, array $preview, array $estimate, DateTimeImmutable $at): array
    {
        $this->assertRequest($scope, $preview, $estimate);
        $period = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

        return DB::transaction(function () use ($scope, $preview, $estimate, $at, $period): array {
            // Global lock always precedes workspace lock, even for replays.
            $global = DB::table('ai_autonomy_global_stops')->where('id', 'global')->lockForUpdate()->first();
            $quota = DB::table('ai_autonomy_workspace_quotas')
                ->where('workspace_id', $scope->workspaceId)
                ->where('period_utc', $period)->lockForUpdate()->first();
            if ($global === null || $quota === null) {
                return $this->held($preview, 'independent_policy_unavailable');
            }
            if ((bool) $global->stopped) {
                return $this->held($preview, 'global_emergency_stop');
            }
            if ((bool) $quota->workspace_stopped) {
                return $this->held($preview, 'workspace_emergency_stop');
            }
            if ((new DateTimeImmutable((string) $quota->policy_expires_at, new DateTimeZone('UTC')))
                ->getTimestamp() <= $at->getTimestamp()) {
                return $this->held($preview, 'policy_expired');
            }
            if ((string) $quota->policy_version !== $preview['policy_version']) {
                return $this->held($preview, 'policy_revision_mismatch');
            }

            $prior = DB::table('ai_autonomy_offline_reservations')
                ->where('workspace_id', $scope->workspaceId)
                ->where('run_id', $preview['run_id'])->lockForUpdate()->first();
            if ($prior !== null) {
                if ((string) $prior->actor_id !== $scope->actorId
                    || $prior->brand_id !== $scope->brandId
                    || (string) $prior->snapshot_sha256 !== $preview['snapshot_sha256']
                    || (string) $prior->policy_version !== $preview['policy_version']
                    || (string) $prior->period_utc !== $period
                    || (string) $prior->status !== 'reserved_offline'
                    || (int) $prior->actions !== $estimate['actions']
                    || (int) $prior->tokens !== $estimate['tokens']
                    || (int) $prior->volume !== $estimate['volume']
                    || (int) $prior->cost_minor !== $estimate['cost_minor']
                    || (int) $prior->attempts !== $estimate['attempts']) {
                    throw new InvalidArgumentException('Conflicting offline autonomy reservation replay rejected.');
                }

                // The exact previous receipt is readable; no second quota
                // increment, even if the first reservation exhausted limits.
                return $this->receipt($preview);
            }

            $preflight = (new BoundedAutonomyOfflineSafetyGate(
                new DatabaseBoundedAutonomySafetySnapshotSource,
            ))->assess($scope, $preview, $estimate, $at, true);
            if ($preflight['status'] !== 'offline_preflight_passed') {
                return $preflight;
            }

            DB::table('ai_autonomy_workspace_quotas')
                ->where('workspace_id', $scope->workspaceId)->where('period_utc', $period)
                ->update([
                    'used_actions' => (int) $quota->used_actions + $estimate['actions'],
                    'used_tokens' => (int) $quota->used_tokens + $estimate['tokens'],
                    'used_volume' => (int) $quota->used_volume + $estimate['volume'],
                    'used_attempts' => (int) $quota->used_attempts + $estimate['attempts'],
                    'reserved_cost_minor' => (int) $quota->reserved_cost_minor + $estimate['cost_minor'],
                    'updated_at' => now(),
                ]);
            DB::table('ai_autonomy_offline_reservations')->insert([
                'workspace_id' => $scope->workspaceId,
                'period_utc' => $period,
                'run_id' => $preview['run_id'],
                'brand_id' => $scope->brandId,
                'actor_id' => $scope->actorId,
                'snapshot_sha256' => $preview['snapshot_sha256'],
                'policy_version' => $preview['policy_version'],
                'actions' => $estimate['actions'],
                'tokens' => $estimate['tokens'],
                'volume' => $estimate['volume'],
                'cost_minor' => $estimate['cost_minor'],
                'attempts' => $estimate['attempts'],
                'status' => 'reserved_offline',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->receipt($preview);
        }, 3);
    }

    public function recheckHeld(TenantContext $scope, array $preview, DateTimeImmutable $at): array
    {
        if (($preview['status'] ?? null) !== 'preview_ready'
            || ($preview['tenant'] ?? null) !== $scope->toArray()
            || ($preview['execution_authorized'] ?? null) !== false
            || ($preview['stages']['execute'] ?? null) !== 'disabled'
            || ! is_string($preview['run_id'] ?? null)
            || ! is_string($preview['snapshot_sha256'] ?? null)) {
            throw new InvalidArgumentException('Invalid autonomy reservation recheck scope.');
        }

        return DB::transaction(function () use ($scope, $preview, $at): array {
            $global = DB::table('ai_autonomy_global_stops')->where('id', 'global')->lockForUpdate()->first();
            $quota = DB::table('ai_autonomy_workspace_quotas')->where('workspace_id', $scope->workspaceId)
                ->where('period_utc', $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'))
                ->lockForUpdate()->first();
            if ($global === null || $quota === null) {
                return $this->held($preview, 'independent_policy_unavailable');
            }
            if ((bool) $global->stopped) {
                return $this->held($preview, 'global_emergency_stop');
            }
            if ((bool) $quota->workspace_stopped) {
                return $this->held($preview, 'workspace_emergency_stop');
            }
            if ((new DateTimeImmutable((string) $quota->policy_expires_at, new DateTimeZone('UTC')))
                ->getTimestamp() <= $at->getTimestamp()) {
                return $this->held($preview, 'policy_expired');
            }
            if ((string) $quota->policy_version !== ($preview['policy_version'] ?? null)) {
                return $this->held($preview, 'policy_revision_mismatch');
            }
            $row = DB::table('ai_autonomy_offline_reservations')->where('workspace_id', $scope->workspaceId)
                ->where('run_id', $preview['run_id'])->lockForUpdate()->first();
            if ($row === null || (string) $row->period_utc !== $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')
                || $row->brand_id !== $scope->brandId
                || $row->actor_id !== $scope->actorId
                || $row->snapshot_sha256 !== $preview['snapshot_sha256']
                || $row->policy_version !== $preview['policy_version']
                || $row->status !== 'reserved_offline') {
                return $this->held($preview, 'reservation_invalidated_or_missing');
            }

            return $this->receipt($preview);
        }, 3);
    }

    private function assertRequest(TenantContext $scope, array $preview, array $estimate): void
    {
        if (($preview['status'] ?? null) !== 'preview_ready'
            || ($preview['tenant'] ?? null) !== $scope->toArray()
            || ($preview['execution_authorized'] ?? null) !== false
            || ($preview['stages']['execute'] ?? null) !== 'disabled'
            || ! is_string($preview['run_id'] ?? null)
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $preview['run_id']) !== 1
            || ! is_string($preview['snapshot_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $preview['snapshot_sha256']) !== 1
            || ! is_string($preview['policy_version'] ?? null)
            || ! is_array($preview['actions'] ?? null) || ! array_is_list($preview['actions'])
            || $preview['actions'] === [] || count($preview['actions']) > 8) {
            throw new InvalidArgumentException('Untrusted offline reservation preview rejected.');
        }
        foreach ($preview['actions'] as $action) {
            if (! is_array($action)
                || ! in_array($action['effect'] ?? null, ['read', 'proposal'], true)
                || ! in_array($action['risk'] ?? null, ['R0', 'R1'], true)) {
                throw new InvalidArgumentException('Autonomy reservation effect escalation rejected.');
            }
        }
        $names = ['actions', 'tokens', 'volume', 'cost_minor', 'attempts'];
        if (count($estimate) !== 5 || array_diff(array_keys($estimate), $names) !== []
            || array_diff($names, array_keys($estimate)) !== []) {
            throw new InvalidArgumentException('Autonomy reservation resource schema rejected.');
        }
        foreach ($estimate as $value) {
            if (! is_int($value) || $value < 0 || $value > 1000000000) {
                throw new InvalidArgumentException('Autonomy reservation resource bounds rejected.');
            }
        }
        if ($estimate['actions'] !== count($preview['actions']) || $estimate['attempts'] < 1) {
            throw new InvalidArgumentException('Autonomy reservation action/attempt mismatch.');
        }
    }

    private function held(array $preview, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => $preview['run_id'],
            'snapshot_sha256' => $preview['snapshot_sha256'],
            'budget_reservation_complete' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private function receipt(array $preview): array
    {
        return [
            'status' => 'reserved_offline',
            'reason_code' => 'final_side_effect_authority_disabled',
            'run_id' => $preview['run_id'],
            'snapshot_sha256' => $preview['snapshot_sha256'],
            'budget_reservation_complete' => true,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
