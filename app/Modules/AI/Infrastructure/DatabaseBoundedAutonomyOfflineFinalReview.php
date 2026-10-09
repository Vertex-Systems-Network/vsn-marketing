<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Application\BoundedAutonomyOfflineApprovalReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyApprovalSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * OFFLINE final preflight over the exact reservation and independent approval.
 *
 * Re-reads global/workspace stops and the durable reservation in locked order.
 * No provider, queue, write, settlement or external dispatch occurs.
 * A review-only positive result NEVER grants execution authority; revocations
 * and provider outcomes still require independent evidence and future gates.
 */
final readonly class DatabaseBoundedAutonomyOfflineFinalReview
{
    public function __construct(private BoundedAutonomyApprovalSource $approvals) {}

    public function inspect(
        TenantContext $actor,
        array $preview,
        array $binding,
        array $estimate,
        DateTimeImmutable $at,
    ): array {
        if (($preview['status'] ?? null) !== 'preview_ready'
            || ($preview['tenant'] ?? null) !== $actor->toArray()
            || ($preview['execution_authorized'] ?? null) !== false
            || ($preview['stages']['execute'] ?? null) !== 'disabled'
            || ! is_string($preview['run_id'] ?? null)
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $preview['run_id']) !== 1
            || ! is_string($preview['policy_version'] ?? null)
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $preview['policy_version']) !== 1
            || ! is_string($preview['snapshot_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $preview['snapshot_sha256']) !== 1) {
            throw new InvalidArgumentException('Trusted offline tenant preview is required.');
        }

        $keys = ['actions', 'tokens', 'volume', 'cost_minor', 'attempts'];
        if (count($estimate) !== count($keys)
            || array_diff(array_keys($estimate), $keys) !== []
            || array_diff($keys, array_keys($estimate)) !== []) {
            throw new InvalidArgumentException('Untrusted final-review resource estimate schema.');
        }
        foreach ($keys as $key) {
            if (! is_int($estimate[$key]) || $estimate[$key] < 0 || $estimate[$key] > 1000000000) {
                throw new InvalidArgumentException('Invalid final-review resource amount.');
            }
        }
        if ($estimate['actions'] < 1 || $estimate['actions'] > 8 || $estimate['attempts'] < 1) {
            throw new InvalidArgumentException('Invalid final-review action/attempt count.');
        }

        return DB::transaction(function () use ($actor, $preview, $binding, $estimate, $at): array {
            $runId = $preview['run_id'];
            $global = DB::table('ai_autonomy_global_stops')->where('id', 'global')
                ->lockForUpdate()->first();
            if ($global === null || (int) $global->stopped !== 0) {
                return $this->held($preview, 'global_emergency_stop_or_unknown');
            }

            $period = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
            $quota = DB::table('ai_autonomy_workspace_quotas')
                ->where('workspace_id', $actor->workspaceId)->where('period_utc', $period)
                ->lockForUpdate()->first();
            if ($quota === null || (int) $quota->workspace_stopped !== 0) {
                return $this->held($preview, 'workspace_emergency_stop_or_unknown');
            }
            if ($quota->policy_version !== $preview['policy_version']
                || (new DateTimeImmutable($quota->policy_expires_at, new DateTimeZone('UTC'))) <= $at) {
                return $this->held($preview, 'workspace_policy_changed_or_expired');
            }

            $record = DB::table('ai_autonomy_offline_reservations')
                ->where('workspace_id', $actor->workspaceId)
                ->where('run_id', $runId)->lockForUpdate()->first();
            if ($record === null) {
                return $this->held($preview, 'offline_reservation_missing');
            }
            if ($record->actor_id !== $actor->actorId
                || $record->brand_id !== $actor->brandId
                || $record->snapshot_sha256 !== $preview['snapshot_sha256']
                || $record->policy_version !== $preview['policy_version']
                || $record->period_utc !== $period) {
                throw new InvalidArgumentException('Final-review reservation scope or immutable policy changed.');
            }
            if ($record->status !== 'offline_reserved') {
                return $this->held($preview, 'reservation_not_active');
            }
            foreach ($estimate as $key => $value) {
                $recordKey = $key === 'cost_minor' ? 'cost_minor' : $key;
                if ((int) $record->{$recordKey} !== $value) {
                    return $this->held($preview, 'reserved_resources_changed');
                }
            }
            if ((int) $quota->reserved_cost_minor + (int) $quota->spent_cost_minor > (int) $quota->max_cost_minor
                || (int) $quota->used_tokens > (int) $quota->max_tokens
                || (int) $quota->used_volume > (int) $quota->max_volume
                || (int) $quota->used_actions > (int) $quota->max_actions
                || (int) $quota->used_attempts > (int) $quota->max_attempts) {
                return $this->held($preview, 'current_budget_exceeded');
            }

            // Re-check independent latest decision and current approver role;
            // this is NOT a lock on future human revocation writes.
            $approval = (new BoundedAutonomyOfflineApprovalReview($this->approvals))
                ->inspect($actor, $preview, $binding, $at);
            if ($approval['status'] !== 'approval_matched_offline') {
                return $this->held($preview, $approval['reason_code']);
            }
            if ($binding['max_cost_minor'] < $estimate['cost_minor']
                || $binding['max_volume'] < $estimate['volume']) {
                return $this->held($preview, 'approved_limit_below_reserved_amount');
            }

            return [
                'status' => 'offline_final_review_passed',
                'reason_code' => 'live_execution_unavailable',
                'run_id' => $runId,
                'snapshot_sha256' => $preview['snapshot_sha256'],
                'approval_decision_id' => $approval['decision_id'],
                'reservation_verified' => true,
                'external_outcome_verified' => false,
                'execution_authorized' => false,
                'promotion_authorized' => false,
            ];
        }, 3);
    }

    private function held(array $preview, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => $preview['run_id'],
            'snapshot_sha256' => $preview['snapshot_sha256'],
            'approval_decision_id' => null,
            'reservation_verified' => false,
            'external_outcome_verified' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
