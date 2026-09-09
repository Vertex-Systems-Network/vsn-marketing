<?php

namespace App\Modules\DeliveryEngine\Domain;

final class DeliveryReconciliationPolicy
{
    public function decide(DeliveryReconciliationEvidence $evidence): DeliveryReconciliationDecision
    {
        if ($evidence->providerAccepted) {
            return new DeliveryReconciliationDecision(
                resolution: DeliveryReconciliationResolution::Accepted,
                accepted: true,
                retryAllowed: false,
                operatorActionRequired: false,
                reason: 'provider_evidence_proves_acceptance',
            );
        }

        if ($evidence->acceptanceKnownNotOccurred && $evidence->retrySafe) {
            return new DeliveryReconciliationDecision(
                resolution: DeliveryReconciliationResolution::NotAcceptedRetrySafe,
                accepted: false,
                retryAllowed: true,
                operatorActionRequired: false,
                reason: 'provider_evidence_proves_not_accepted_and_retry_safe',
            );
        }

        if ($evidence->acceptanceKnownNotOccurred) {
            return new DeliveryReconciliationDecision(
                resolution: DeliveryReconciliationResolution::OperatorResolutionRequired,
                accepted: false,
                retryAllowed: false,
                operatorActionRequired: true,
                reason: 'non_acceptance_proven_but_automatic_retry_not_safe',
            );
        }

        if ($evidence->probeBudgetRemains()) {
            return new DeliveryReconciliationDecision(
                resolution: DeliveryReconciliationResolution::Pending,
                accepted: false,
                retryAllowed: false,
                operatorActionRequired: false,
                reason: 'acceptance_unresolved_continue_bounded_reconciliation',
            );
        }

        return new DeliveryReconciliationDecision(
            resolution: DeliveryReconciliationResolution::OperatorResolutionRequired,
            accepted: false,
            retryAllowed: false,
            operatorActionRequired: true,
            reason: 'acceptance_unresolved_after_probe_budget_exhausted',
        );
    }
}
