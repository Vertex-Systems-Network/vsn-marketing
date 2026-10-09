<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyObservationSource;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Offline observation only: independent evidence -> immutable operator-review
 * recommendation. Cannot execute, send, spend, publish or promote campaigns.
 */
final readonly class BoundedAutonomyOfflineEvaluation
{
    public function __construct(
        private BoundedAutonomyOfflineReceipt $receipts,
        private BoundedAutonomyObservationSource $source,
        private IdempotentExecutor $idempotency,
    ) {}

    public function evaluate(
        TenantContext $scope,
        string $runId,
        array $goal,
        array $actions,
        string $sourceId,
        DateTimeImmutable $at,
    ): array {
        // A persisted exact proposal receipt is mandatory; no caller may
        // manufacture a preview digest or skip the offline-only policy.
        $proposal = $this->receipts->record($scope, $runId, $goal, $actions, $at);
        $allowed = false;
        foreach ($actions as $action) {
            if (in_array($sourceId, $action['source_ids'], true)) {
                $allowed = true;
                break;
            }
        }
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $sourceId) !== 1 || $allowed === false) {
            throw new InvalidArgumentException('Autonomy observation source is not in approved proposal scope.');
        }

        $fact = $this->source->verifiedCount($scope, $sourceId, $goal['metric_id'], $at);
        if ($fact === null) {
            return [
                'status' => 'awaiting_verified_observation',
                'snapshot_sha256' => $proposal['snapshot_sha256'],
                'reason_code' => 'no_independently_verified_evidence',
                'execution_authorized' => false,
                'promotion_authorized' => false,
            ];
        }
        if (is_array($fact) === false || array_diff(array_keys($fact), [
            'workspace_id', 'brand_id', 'source_id', 'metric_id', 'count', 'observed_at_unix', 'evidence_sha256',
        ]) !== [] || count($fact) !== 7
            || ($fact['workspace_id'] ?? null) !== $scope->workspaceId
            || ($fact['brand_id'] ?? null) !== $scope->brandId
            || ($fact['source_id'] ?? null) !== $sourceId
            || ($fact['metric_id'] ?? null) !== $goal['metric_id']
            || is_int($fact['count'] ?? null) === false || $fact['count'] < 0 || $fact['count'] > 1000000
            || is_int($fact['observed_at_unix'] ?? null) === false
            || $fact['observed_at_unix'] > $at->getTimestamp()
            || $fact['observed_at_unix'] < $at->getTimestamp() - 86400
            || is_string($fact['evidence_sha256'] ?? null) === false
            || preg_match('/^[a-f0-9]{64}$/D', $fact['evidence_sha256']) !== 1) {
            throw new InvalidArgumentException('Untrusted autonomy observation facts rejected.');
        }

        // The metric threshold is a review signal, never causal uplift,
        // experiment promotion, consent grant or execution authorization.
        $decision = $fact['count'] >= $goal['target_count'] ? 'target_met_operator_review' : 'hold_below_target';
        $fingerprint = hash('sha256', json_encode([
            $proposal['snapshot_sha256'], $scope->toArray(), $goal['policy_version'],
            $sourceId, $fact, $decision,
        ], JSON_THROW_ON_ERROR));
        $outcome = $this->idempotency->run(
            $scope->workspaceId,
            'ai-offline-autonomy-observation:v1',
            $runId,
            static fn (): array => [
                'status' => 'evaluated_offline',
                'run_id' => $runId,
                'tenant' => $scope->toArray(),
                'snapshot_sha256' => $proposal['snapshot_sha256'],
                'observation_sha256' => $fingerprint,
                'evidence_sha256' => $fact['evidence_sha256'],
                'decision' => $decision,
                'observed_count' => $fact['count'],
                'causal_lift_proven' => false,
                'execution_authorized' => false,
                'promotion_authorized' => false,
            ],
            $scope->actorId,
        );
        if (($outcome['status'] ?? null) !== 'evaluated_offline'
            || ($outcome['run_id'] ?? null) !== $runId
            || ($outcome['tenant'] ?? null) !== $scope->toArray()
            || ($outcome['snapshot_sha256'] ?? null) !== $proposal['snapshot_sha256']
            || ($outcome['observation_sha256'] ?? null) !== $fingerprint
            || ($outcome['evidence_sha256'] ?? null) !== $fact['evidence_sha256']
            || ($outcome['decision'] ?? null) !== $decision
            || ($outcome['observed_count'] ?? null) !== $fact['count']
            || ($outcome['causal_lift_proven'] ?? null) !== false
            || ($outcome['execution_authorized'] ?? null) !== false
            || ($outcome['promotion_authorized'] ?? null) !== false) {
            throw new InvalidArgumentException('Conflicting or tampered autonomy observation replay rejected.');
        }

        return $outcome;
    }
}
