<?php

namespace App\Modules\DeliveryEngine\Application\SuppressionSync;

use InvalidArgumentException;

final class SynchronizeSuppression
{
    public function handle(
        SuppressionSynchronizationRequest $request,
        ?SuppressionSynchronizationResult $previousResult = null,
    ): SuppressionSynchronizationResult {
        $reasons = [
            match ($request->providerOutcome) {
                SuppressionSynchronizationOutcome::Confirmed => 'provider_suppression_confirmed',
                SuppressionSynchronizationOutcome::Rejected => 'provider_suppression_rejected',
                SuppressionSynchronizationOutcome::Timeout => 'provider_suppression_timeout',
                SuppressionSynchronizationOutcome::Ambiguous => 'provider_suppression_ambiguous',
            },
            'internal_suppression_remains_authoritative',
            'provider_reconciliation_cannot_restore_eligibility',
        ];

        if ($request->providerOutcome->requiresReconciliation()) {
            $reasons[] = 'provider_outcome_requires_reconciliation';
        }

        $candidate = new SuppressionSynchronizationResult(
            operationKey: $request->operationKey,
            workspaceId: $request->workspaceId,
            suppressionRecordId: $request->suppressionRecordId,
            providerKey: $request->providerKey,
            providerOutcome: $request->providerOutcome,
            reconciliationRequired: $request->providerOutcome->requiresReconciliation(),
            internalSuppressionActive: true,
            restoresEligibility: false,
            reasons: array_values(array_unique($reasons)),
            observedAt: $request->observedAt,
            providerReference: $request->providerReference,
        );

        if ($previousResult === null) {
            return $candidate;
        }

        if (
            $previousResult->operationKey !== $request->operationKey
            || $previousResult->workspaceId !== $request->workspaceId
            || $previousResult->suppressionRecordId !== $request->suppressionRecordId
            || $previousResult->providerKey !== $request->providerKey
        ) {
            throw new InvalidArgumentException('Suppression synchronization replay scope does not match the previous result.');
        }

        if ($previousResult != $candidate) {
            throw new InvalidArgumentException('Suppression synchronization operation key conflicts with a different replay outcome.');
        }

        return $previousResult;
    }
}
