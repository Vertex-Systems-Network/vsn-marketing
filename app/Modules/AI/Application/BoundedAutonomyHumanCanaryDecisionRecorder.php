<?php

namespace App\Modules\AI\Application;

use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Human-authenticated, append-only OFFLINE canary review decision recorder.
 *
 * No routes or external side effects are installed. Positive decisions require
 * a fresh independently joined canary signal and global/workspace stop checks.
 * Revocation remains possible while stopped. No decision promotes anything.
 */
final readonly class BoundedAutonomyHumanCanaryDecisionRecorder
{
    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private BoundedAutonomyOfflineOutcomeJoinReview $joinedOutcomes,
    ) {}

    public function record(
        User $approver,
        TenantContext $requester,
        ExperimentPlan $experiment,
        ExperimentAnalysisPlan $analysis,
        string $outcome,
        DateTimeImmutable $at,
    ): array {
        $approverId = (string) $approver->getKey();
        if ((string) Auth::id() !== $approverId
            || $approverId === $requester->actorId
            || ! in_array($outcome, ['approved', 'rejected', 'revoked'], true)
            || $requester->actorId === ''
            || ! DB::table('workspaces')->where('id', $requester->workspaceId)
                ->where('organization_id', $requester->organizationId)->exists()
            || $experiment->workspaceId !== $requester->workspaceId
            || $experiment->brandId !== $requester->brandId
            || $experiment->weights !== $analysis->weights
            || $experiment->control !== $analysis->control
            || $experiment->holdout === null
            || $experiment->holdout !== $analysis->holdout
            || $experiment->unitKind !== $analysis->unitKind
            || ! $this->hasAuthority($approver, $requester)) {
            throw new InvalidArgumentException('Independent authenticated canary approver required.');
        }

        return DB::transaction(function () use (
            $approver, $approverId, $requester, $experiment, $analysis, $outcome, $at,
        ): array {
            // Locks agree with final admission: global stop first. No AI output
            // may override the current stop, policy or human permission.
            $global = DB::table('ai_autonomy_global_stops')->where('id', 'global')
                ->lockForUpdate()->first();
            if ($global === null) {
                return $this->hold('global_stop_unconfigured');
            }
            if (! DB::table('workspaces')->where('id', $requester->workspaceId)
                ->where('organization_id', $requester->organizationId)->exists()
                || ! $this->hasAuthority($approver, $requester)) {
                throw new InvalidArgumentException('Current human authority or organization changed.');
            }

            $canonical = DB::table('experiments')
                ->where('id', $experiment->id)
                ->where('workspace_id', $requester->workspaceId)
                ->where('brand_id', $requester->brandId)
                ->lockForUpdate()->first();
            if ($canonical === null || $canonical->plan_hash !== $experiment->fingerprint()
                || $canonical->status !== 'active'
                || $canonical->approved_by_actor_id === null
                || $canonical->approved_by_actor_id === $canonical->created_by_actor_id) {
                return $this->hold('frozen_canary_authority_unavailable');
            }

            $last = DB::table('ai_autonomy_canary_human_decisions')
                ->where('workspace_id', $requester->workspaceId)
                ->where('experiment_id', $experiment->id)
                ->orderByDesc('sequence')->first();

            if ($outcome === 'approved') {
                if ((int) $global->stopped !== 0) {
                    return $this->hold('global_emergency_stop');
                }
                $period = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
                $quota = DB::table('ai_autonomy_workspace_quotas')
                    ->where('workspace_id', $requester->workspaceId)
                    ->where('period_utc', $period)->lockForUpdate()->first();
                if ($quota === null || (int) $quota->workspace_stopped !== 0) {
                    return $this->hold('workspace_emergency_stop');
                }
                if ((new DateTimeImmutable($quota->policy_expires_at, new DateTimeZone('UTC')))
                    <= $at) {
                    return $this->hold('workspace_policy_expired');
                }

                // Recompute independent join facts inside the transaction; no
                // model-supplied scoring payload can create a positive decision.
                $joined = $this->joinedOutcomes->inspect($requester, $experiment, $analysis, $at);
                if (($joined['status'] ?? null) !== 'offline_joined_operator_review_candidate'
                    || ($joined['reason_code'] ?? null) !== 'independent_human_promotion_gate_required'
                    || ($joined['execution_authorized'] ?? null) !== false
                    || ($joined['promotion_authorized'] ?? null) !== false
                    || ($joined['external_outcome_proven'] ?? null) !== false
                    || ($joined['plan_sha256'] ?? null) !== $experiment->fingerprint()
                    || ($joined['analysis_sha256'] ?? null) !== $analysis->fingerprint()
                    || ! self::digest($joined['outcome_manifest_sha256'] ?? null)
                    || ! self::identifier($joined['cohort_receipt_id'] ?? null)) {
                    return $this->hold('independent_outcome_join_not_eligible');
                }
                $binding = [
                    'cohort_receipt_id' => $joined['cohort_receipt_id'],
                    'outcome_manifest_sha256' => $joined['outcome_manifest_sha256'],
                ];
            } else {
                // Human revocation/rejection must remain possible while stopped
                // and must retain exact previous immutable provenance.
                if ($last === null || $last->plan_sha256 !== $experiment->fingerprint()
                    || $last->analysis_sha256 !== $analysis->fingerprint()
                    || ! self::digest($last->outcome_manifest_sha256)) {
                    return $this->hold('prior_human_decision_missing_or_changed');
                }
                if ($outcome === 'revoked' && $last->outcome === 'revoked') {
                    return [
                        'status' => 'human_decision_replayed_offline',
                        'decision_id' => $last->decision_id,
                        'outcome' => 'revoked',
                        'execution_authorized' => false,
                        'promotion_authorized' => false,
                    ];
                }
                $binding = [
                    'cohort_receipt_id' => $last->cohort_receipt_id,
                    'outcome_manifest_sha256' => $last->outcome_manifest_sha256,
                ];
            }

            // Repeated independent approval of the same frozen evidence
            // is an idempotent readback, not a second positive decision.
            // This is evaluated AFTER current session/RBAC, stop, quota and
            // independent outcome evidence have all been revalidated.
            if ($outcome === 'approved' && $last !== null
                && $last->outcome === 'approved'
                && $last->deciding_actor_id === $approverId
                && $last->plan_sha256 === $experiment->fingerprint()
                && $last->analysis_sha256 === $analysis->fingerprint()
                && $last->cohort_receipt_id === $binding['cohort_receipt_id']
                && $last->outcome_manifest_sha256 === $binding['outcome_manifest_sha256']
                && $last->policy_version === 'v1'
                && (int) $last->observed_at_unix <= $at->getTimestamp()
                && (int) $last->expires_at_unix > $at->getTimestamp()) {
                return [
                    'status' => 'human_decision_replayed_offline',
                    'decision_id' => $last->decision_id,
                    'outcome' => 'approved',
                    'execution_authorized' => false,
                    'promotion_authorized' => false,
                ];
            }

            $id = (string) Str::uuid();
            $record = [
                'decision_id' => $id,
                'workspace_id' => $requester->workspaceId,
                'brand_id' => $requester->brandId,
                'experiment_id' => $experiment->id,
                'sequence' => $last === null ? 1 : (int) $last->sequence + 1,
                'plan_sha256' => $experiment->fingerprint(),
                'analysis_sha256' => $analysis->fingerprint(),
                'cohort_receipt_id' => $binding['cohort_receipt_id'],
                'outcome_manifest_sha256' => $binding['outcome_manifest_sha256'],
                'deciding_actor_id' => $approverId,
                'outcome' => $outcome,
                'policy_version' => 'v1',
                'human_session_verified' => true,
                'session_proof_sha256' => hash('sha256', implode(':', [
                    $approverId, $requester->workspaceId, $experiment->id, $id,
                    (string) $at->getTimestamp(),
                ])),
                'observed_at_unix' => $at->getTimestamp(),
                'expires_at_unix' => $at->getTimestamp() + 300,
                'created_at' => now(),
            ];

            DB::table('ai_autonomy_canary_human_decisions')->insert($record);

            return [
                'status' => 'human_decision_recorded_offline',
                'decision_id' => $id,
                'outcome' => $outcome,
                'execution_authorized' => false,
                'promotion_authorized' => false,
            ];
        }, 3);
    }

    private function hasAuthority(User $approver, TenantContext $requester): bool
    {
        $scope = new TenantContext(
            $requester->organizationId, $requester->workspaceId, $requester->brandId,
            (string) $approver->getKey(),
        );

        return $this->authorizer->allows($approver, $scope, PermissionCatalog::AI_APPROVE)
            && $this->authorizer->allows($approver, $scope, PermissionCatalog::CAMPAIGN_APPROVE);
    }

    private function hold(string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }
}
