<?php

namespace App\Modules\DeliveryEngine\Application\Deliverability;

use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilityObservation;
use DateTimeImmutable;
use InvalidArgumentException;

final class EvaluateDeliverabilityDiagnostics
{
    /** @param list<mixed> $observations */
    public function evaluate(
        string $workspaceId,
        string $providerKey,
        string $messagePurpose,
        array $observations,
        DateTimeImmutable $evaluatedAt,
    ): DeliverabilityDiagnosticResult {
        foreach ([
            'workspaceId' => $workspaceId,
            'providerKey' => $providerKey,
            'messagePurpose' => $messagePurpose,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must be non-blank.');
            }
        }

        if ($observations === []) {
            return $this->result(
                DeliverabilityDiagnosticResult::STATUS_UNKNOWN,
                $workspaceId,
                $providerKey,
                $messagePurpose,
                ['deliverability_evidence_missing'],
                [],
                [],
                $evaluatedAt,
            );
        }

        $evidenceIds = [];
        $signalKinds = [];
        $issues = [];
        $signalValues = [];

        foreach ($observations as $observation) {
            if (! $observation instanceof DeliverabilityObservation) {
                $issues['deliverability_evidence_malformed'] = true;

                continue;
            }

            $evidenceIds[] = $observation->id;

            if ($observation->workspaceId !== $workspaceId) {
                $issues['deliverability_evidence_workspace_mismatch'] = true;

                continue;
            }

            if ($observation->providerKey !== $providerKey) {
                $issues['deliverability_evidence_provider_mismatch'] = true;

                continue;
            }

            if ($observation->messagePurpose !== $messagePurpose) {
                $issues['deliverability_evidence_message_purpose_mismatch'] = true;

                continue;
            }

            if (! $observation->trusted) {
                $issues['deliverability_evidence_untrusted'] = true;

                continue;
            }

            if ($observation->effectiveAt > $evaluatedAt) {
                $issues['deliverability_evidence_not_yet_effective'] = true;

                continue;
            }

            if ($observation->observedAt > $evaluatedAt) {
                $issues['deliverability_evidence_observed_in_future'] = true;

                continue;
            }

            if ($observation->recordedAt > $evaluatedAt) {
                $issues['deliverability_evidence_recorded_in_future'] = true;

                continue;
            }

            if ($observation->isStaleAt($evaluatedAt)) {
                $issues['deliverability_evidence_stale'] = true;

                continue;
            }

            $signalKinds[$observation->kind->value] = true;
            $identity = implode("\0", [
                $observation->kind->value,
                $observation->signalKey,
                $observation->version,
                $observation->effectiveAt->format(DATE_ATOM),
                $observation->observedAt->format(DATE_ATOM),
            ]);
            $signalValues[$identity][$observation->signalValue] = true;
        }

        if ($issues !== []) {
            return $this->result(
                DeliverabilityDiagnosticResult::STATUS_REVIEW,
                $workspaceId,
                $providerKey,
                $messagePurpose,
                array_keys($issues),
                $evidenceIds,
                array_keys($signalKinds),
                $evaluatedAt,
            );
        }

        foreach ($signalValues as $values) {
            if (count($values) > 1) {
                return $this->result(
                    DeliverabilityDiagnosticResult::STATUS_REVIEW,
                    $workspaceId,
                    $providerKey,
                    $messagePurpose,
                    ['deliverability_evidence_contradictory'],
                    $evidenceIds,
                    array_keys($signalKinds),
                    $evaluatedAt,
                );
            }
        }

        if ($signalKinds === []) {
            return $this->result(
                DeliverabilityDiagnosticResult::STATUS_UNKNOWN,
                $workspaceId,
                $providerKey,
                $messagePurpose,
                ['deliverability_evidence_missing'],
                $evidenceIds,
                [],
                $evaluatedAt,
            );
        }

        $reasons = ['deliverability_evidence_observed'];

        foreach (array_keys($signalKinds) as $kind) {
            $reasons[] = 'deliverability_signal_observed:'.$kind;
        }

        return $this->result(
            DeliverabilityDiagnosticResult::STATUS_OBSERVED,
            $workspaceId,
            $providerKey,
            $messagePurpose,
            $reasons,
            $evidenceIds,
            array_keys($signalKinds),
            $evaluatedAt,
        );
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $evidenceIds
     * @param  list<string>  $signalKinds
     */
    private function result(
        string $status,
        string $workspaceId,
        string $providerKey,
        string $messagePurpose,
        array $reasons,
        array $evidenceIds,
        array $signalKinds,
        DateTimeImmutable $evaluatedAt,
    ): DeliverabilityDiagnosticResult {
        $reasons = array_values(array_unique($reasons));
        $evidenceIds = array_values(array_unique($evidenceIds));
        $signalKinds = array_values(array_unique($signalKinds));

        sort($reasons);
        sort($evidenceIds);
        sort($signalKinds);

        return new DeliverabilityDiagnosticResult(
            status: $status,
            workspaceId: $workspaceId,
            providerKey: $providerKey,
            messagePurpose: $messagePurpose,
            reasons: $reasons,
            evidenceIds: $evidenceIds,
            signalKinds: $signalKinds,
            evaluatedAt: $evaluatedAt,
        );
    }
}
