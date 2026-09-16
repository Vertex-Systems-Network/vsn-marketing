<?php

namespace App\Modules\DeliveryEngine\Application\SenderSync;

use InvalidArgumentException;

final readonly class SynchronizeSenderDomain
{
    public function handle(
        SenderSynchronizationRequest $request,
        ?SenderSynchronizationResult $previousResult = null,
    ): SenderSynchronizationResult {
        $reasons = [];

        foreach ($request->verification->reasons as $reason) {
            $reasons[] = 'verification:'.$reason;
        }

        if (! $request->verification->eligibleForLaterSendingEvaluation) {
            $reasons[] = 'verification_not_ready_for_synchronization';
        }

        $reasons[] = match ($request->providerOutcome) {
            SenderSynchronizationOutcome::Confirmed => 'provider_synchronization_confirmed',
            SenderSynchronizationOutcome::Rejected => 'provider_synchronization_rejected',
            SenderSynchronizationOutcome::Timeout => 'provider_synchronization_timeout',
            SenderSynchronizationOutcome::Ambiguous => 'provider_synchronization_ambiguous',
        };

        if ($request->providerOutcome->requiresReconciliation()) {
            $reasons[] = 'provider_outcome_requires_reconciliation';
        }

        $eligibleForLaterSendingEvaluation = $request->verification->eligibleForLaterSendingEvaluation
            && $request->providerOutcome === SenderSynchronizationOutcome::Confirmed;

        if ($eligibleForLaterSendingEvaluation) {
            $reasons[] = 'synchronization_ready_for_later_sending_evaluation';
        }

        $reasons[] = 'production_activation_requires_separate_gate';

        $candidate = new SenderSynchronizationResult(
            operationKey: $request->operationKey,
            workspaceId: $request->workspaceId,
            senderDomainId: $request->senderDomainId,
            providerKey: $request->providerKey,
            providerOutcome: $request->providerOutcome,
            reconciliationRequired: $request->providerOutcome->requiresReconciliation(),
            eligibleForLaterSendingEvaluation: $eligibleForLaterSendingEvaluation,
            productionActivationAllowed: false,
            reasons: array_values(array_unique($reasons)),
            publicEvidence: $request->publicEvidence,
            observedAt: $request->observedAt,
            providerReference: $request->providerReference,
            sourceVersion: $request->sourceVersion,
        );

        if ($previousResult === null) {
            return $candidate;
        }

        if (
            $previousResult->operationKey !== $request->operationKey
            || $previousResult->workspaceId !== $request->workspaceId
            || $previousResult->senderDomainId !== $request->senderDomainId
            || $previousResult->providerKey !== $request->providerKey
        ) {
            throw new InvalidArgumentException('Sender synchronization replay scope does not match the supplied previous result.');
        }

        if ($previousResult != $candidate) {
            throw new InvalidArgumentException('Sender synchronization operation key conflicts with a different replay outcome.');
        }

        return $previousResult;
    }
}
