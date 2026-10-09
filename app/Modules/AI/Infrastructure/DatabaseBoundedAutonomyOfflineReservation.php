<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Application\BoundedAutonomyOfflineSafetyGate;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Atomic, workspace-scoped OFFLINE reservation only.
 *
 * Global/workspace stop rows and quota counters are locked in one transaction.
 * A missing authority row, stale policy, forged preview or oversubscription
 * cannot claim a resource. This service has no provider/queue adapter and
 * never authorizes sending, publishing, billing or promotion.
 */
final class DatabaseBoundedAutonomyOfflineReservation
{
    public function reserve(TenantContext $actor, array $preview, array $estimate, DateTimeImmutable $at): array
    {
        if ($actor->workspaceId === '' || $actor->actorId === '' || strlen($actor->actorId) > 64) {
            throw new InvalidArgumentException('Canonical offline reservation actor required.');
        }

        return DB::transaction(function () use ($actor, $preview, $estimate, $at): array {
            $global = DB::table('ai_autonomy_global_stops')->where('id', 'global')
                ->lockForUpdate()->first();

            if ($global === null) {
                return $this->result($preview, 'global_stop_unconfigured');
            }

            if ((int) $global->stopped !== 0) {
                return $this->result($preview, 'global_emergency_stop');
            }

            $period = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
            $quota = DB::table('ai_autonomy_workspace_quotas')
                ->where('workspace_id', $actor->workspaceId)->where('period_utc', $period)
                ->lockForUpdate()->first();

            if ($quota === null) {
                return $this->result($preview, 'workspace_quota_unconfigured');
            }

            $expiry = (new DateTimeImmutable($quota->policy_expires_at, new DateTimeZone('UTC')))->getTimestamp();
            $snapshot = [
                'workspace_id' => $actor->workspaceId,
                'brand_id' => $actor->brandId,
                'policy_version' => $quota->policy_version,
                'observed_at_unix' => $at->getTimestamp(),
                'expires_at_unix' => min($expiry, $at->getTimestamp() + 3600),
                'global_stopped' => false, // The preceding locked gate already denied any stopped value.
                'workspace_stopped' => (int) $quota->workspace_stopped !== 0,
                'max_actions' => (int) $quota->max_actions,
                'max_tokens' => (int) $quota->max_tokens,
                'max_volume' => (int) $quota->max_volume,
                'max_cost_minor' => (int) $quota->max_cost_minor,
                'max_attempts' => (int) $quota->max_attempts,
                'used_actions' => (int) $quota->used_actions,
                'used_tokens' => (int) $quota->used_tokens,
                'used_volume' => (int) $quota->used_volume,
                'reserved_cost_minor' => (int) $quota->reserved_cost_minor,
                'spent_cost_minor' => (int) $quota->spent_cost_minor,
                'used_attempts' => (int) $quota->used_attempts,
            ];

            // The source is generated solely from the row-locked database
            // snapshot, never supplied by a caller, prompt or adapter.
            $source = new LockedBoundedAutonomySafetySnapshotSource($snapshot);

            // Always check an already-recorded run before evaluating *remaining*
            // budget. A replay never creates a second reservation, even when
            // the original claim exhausted the available quota.
            $recorded = is_string($preview['run_id'] ?? null)
                ? DB::table('ai_autonomy_offline_reservations')
                    ->where('workspace_id', $actor->workspaceId)
                    ->where('run_id', $preview['run_id'])->first()
                : null;
            if ($recorded !== null) {
                if ($recorded->actor_id !== $actor->actorId
                    || $recorded->brand_id !== $actor->brandId
                    || $recorded->snapshot_sha256 !== ($preview['snapshot_sha256'] ?? null)
                    || $recorded->policy_version !== ($preview['policy_version'] ?? null)
                    || ($preview['execution_authorized'] ?? null) !== false) {
                    throw new InvalidArgumentException('Conflicting offline reservation replay rejected.');
                }

                return $this->result($preview, 'run_already_recorded');
            }

            $review = (new BoundedAutonomyOfflineSafetyGate($source))->assess(
                $actor, $preview, $estimate, $at,
            );

            if ($review['status'] !== 'offline_preflight_passed') {
                return $this->result($preview, $review['reason_code']);
            }

            // Same transaction and lock order as the durable quota claim:
            // global stop -> workspace quota -> rate window -> reservation.
            // A missing/stale policy blocks new work; never infer a rate grant.
            $rate = DB::table('ai_autonomy_workspace_rate_windows')
                ->where('workspace_id', $actor->workspaceId)->where('period_utc', $period)
                ->lockForUpdate()->first();
            if ($rate === null) {
                return $this->result($preview, 'rate_policy_unconfigured');
            }
            if ($rate->policy_version !== $preview['policy_version']) {
                return $this->result($preview, 'rate_policy_changed');
            }
            $maximum = (int) $rate->max_attempts_per_minute;
            $used = (int) $rate->window_used_attempts;
            $minute = intdiv($at->getTimestamp(), 60) * 60;
            $previousMinute = (int) $rate->window_started_unix;
            if ($maximum < 1 || $maximum > 100000 || $used < 0 || $used > $maximum
                || $minute < 0 || $previousMinute < 0) {
                throw new InvalidArgumentException('Untrusted offline rate policy counters.');
            }
            if ($previousMinute > $minute) {
                return $this->result($preview, 'rate_clock_regressed');
            }
            $currentUsed = $previousMinute === $minute ? $used : 0;
            if ($estimate['attempts'] > $maximum - $currentUsed) {
                return $this->result($preview, 'rate_limit_reached');
            }

            DB::table('ai_autonomy_workspace_rate_windows')
                ->where('workspace_id', $actor->workspaceId)->where('period_utc', $period)
                ->update([
                    'window_started_unix' => $minute,
                    'window_used_attempts' => $currentUsed + $estimate['attempts'],
                    'updated_at' => now(),
                ]);

            DB::table('ai_autonomy_workspace_quotas')
                ->where('workspace_id', $actor->workspaceId)->where('period_utc', $period)
                ->update([
                    'used_actions' => (int) $quota->used_actions + $estimate['actions'],
                    'used_tokens' => (int) $quota->used_tokens + $estimate['tokens'],
                    'used_volume' => (int) $quota->used_volume + $estimate['volume'],
                    'reserved_cost_minor' => (int) $quota->reserved_cost_minor + $estimate['cost_minor'],
                    'used_attempts' => (int) $quota->used_attempts + $estimate['attempts'],
                    'updated_at' => now(),
                ]);

            DB::table('ai_autonomy_offline_reservations')->insert([
                'workspace_id' => $actor->workspaceId,
                'period_utc' => $period,
                'run_id' => $preview['run_id'],
                'brand_id' => $actor->brandId,
                'actor_id' => $actor->actorId,
                'snapshot_sha256' => $preview['snapshot_sha256'],
                'policy_version' => $preview['policy_version'],
                'actions' => $estimate['actions'],
                'tokens' => $estimate['tokens'],
                'volume' => $estimate['volume'],
                'cost_minor' => $estimate['cost_minor'],
                'attempts' => $estimate['attempts'],
                'status' => 'offline_reserved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => 'reserved_offline',
                'reason_code' => 'requires_independent_approval_and_final_admission',
                'run_id' => $preview['run_id'],
                'snapshot_sha256' => $preview['snapshot_sha256'],
                'offline_resource_reservation_recorded' => true,
                'execution_authorized' => false,
                'promotion_authorized' => false,
            ];
        }, 3);
    }

    private function result(array $preview, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => is_string($preview['run_id'] ?? null) ? $preview['run_id'] : null,
            'snapshot_sha256' => is_string($preview['snapshot_sha256'] ?? null)
                ? $preview['snapshot_sha256'] : null,
            'offline_resource_reservation_recorded' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
