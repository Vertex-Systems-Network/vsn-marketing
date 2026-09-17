<?php

namespace App\Modules\DeliveryEngine\Application\Reputation;

use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Reputation\ReputationHealthEvidence;
use App\Modules\DeliveryEngine\Domain\Reputation\ReputationHealthStatus;
use DateTimeImmutable;
use InvalidArgumentException;

final class EvaluateReputationHealth
{
    /** @param list<mixed> $evidence */
    public function evaluate(
        string $workspaceId,
        string $providerKey,
        array $evidence,
        DateTimeImmutable $evaluatedAt,
    ): ReputationHealthEvaluationResult {
        foreach ([
            'workspaceId' => $workspaceId,
            'providerKey' => $providerKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must be non-blank.');
            }
        }

        if ($evidence === []) {
            return new ReputationHealthEvaluationResult(
                outcome: EligibilityOutcome::Unknown,
                reasons: ['reputation_evidence_missing'],
                evaluatedAt: $evaluatedAt,
                providerKey: $providerKey,
                evidenceIds: [],
            );
        }

        $statuses = [];
        $evidenceIds = [];

        foreach ($evidence as $item) {
            if (! $item instanceof ReputationHealthEvidence) {
                return $this->result(
                    EligibilityOutcome::Review,
                    ['reputation_evidence_malformed'],
                    $evaluatedAt,
                    $providerKey,
                    $evidenceIds,
                );
            }

            $evidenceIds[] = $item->id;

            if ($item->workspaceId !== $workspaceId) {
                return $this->result(
                    EligibilityOutcome::Deny,
                    ['reputation_evidence_workspace_mismatch'],
                    $evaluatedAt,
                    $providerKey,
                    $evidenceIds,
                );
            }

            if ($item->providerKey !== $providerKey) {
                return $this->result(
                    EligibilityOutcome::Review,
                    ['reputation_evidence_provider_mismatch'],
                    $evaluatedAt,
                    $providerKey,
                    $evidenceIds,
                );
            }

            if (! $item->trusted) {
                return $this->result(
                    EligibilityOutcome::Review,
                    ['reputation_evidence_untrusted'],
                    $evaluatedAt,
                    $providerKey,
                    $evidenceIds,
                );
            }

            if ($item->effectiveAt > $evaluatedAt) {
                return $this->result(
                    EligibilityOutcome::Review,
                    ['reputation_evidence_not_yet_effective'],
                    $evaluatedAt,
                    $providerKey,
                    $evidenceIds,
                );
            }

            if ($item->observedAt > $evaluatedAt) {
                return $this->result(
                    EligibilityOutcome::Review,
                    ['reputation_evidence_observed_in_future'],
                    $evaluatedAt,
                    $providerKey,
                    $evidenceIds,
                );
            }

            if ($item->freshUntil < $evaluatedAt) {
                return $this->result(
                    EligibilityOutcome::Review,
                    ['reputation_evidence_stale'],
                    $evaluatedAt,
                    $providerKey,
                    $evidenceIds,
                );
            }

            $statuses[$item->status->value] = $item->status;
        }

        if (count($statuses) > 1) {
            if (isset($statuses[ReputationHealthStatus::Blocked->value])) {
                return $this->result(
                    EligibilityOutcome::Deny,
                    ['reputation_evidence_contradictory', 'reputation_health_blocked'],
                    $evaluatedAt,
                    $providerKey,
                    $evidenceIds,
                );
            }

            return $this->result(
                EligibilityOutcome::Review,
                ['reputation_evidence_contradictory'],
                $evaluatedAt,
                $providerKey,
                $evidenceIds,
            );
        }

        $status = reset($statuses);

        if (! $status instanceof ReputationHealthStatus) {
            return $this->result(
                EligibilityOutcome::Unknown,
                ['reputation_health_unknown'],
                $evaluatedAt,
                $providerKey,
                $evidenceIds,
            );
        }

        return match ($status) {
            ReputationHealthStatus::Healthy => $this->result(
                EligibilityOutcome::Allow,
                ['reputation_health_healthy'],
                $evaluatedAt,
                $providerKey,
                $evidenceIds,
            ),
            ReputationHealthStatus::Degraded => $this->result(
                EligibilityOutcome::Review,
                ['reputation_health_degraded'],
                $evaluatedAt,
                $providerKey,
                $evidenceIds,
            ),
            ReputationHealthStatus::Blocked => $this->result(
                EligibilityOutcome::Deny,
                ['reputation_health_blocked'],
                $evaluatedAt,
                $providerKey,
                $evidenceIds,
            ),
            ReputationHealthStatus::Unknown => $this->result(
                EligibilityOutcome::Unknown,
                ['reputation_health_unknown'],
                $evaluatedAt,
                $providerKey,
                $evidenceIds,
            ),
        };
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $evidenceIds
     */
    private function result(
        EligibilityOutcome $outcome,
        array $reasons,
        DateTimeImmutable $evaluatedAt,
        string $providerKey,
        array $evidenceIds,
    ): ReputationHealthEvaluationResult {
        return new ReputationHealthEvaluationResult(
            outcome: $outcome,
            reasons: $reasons,
            evaluatedAt: $evaluatedAt,
            providerKey: $providerKey,
            evidenceIds: $evidenceIds,
        );
    }
}
