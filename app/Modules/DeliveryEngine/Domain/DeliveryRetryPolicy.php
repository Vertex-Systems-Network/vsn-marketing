<?php

namespace App\Modules\DeliveryEngine\Domain;

use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;

final readonly class DeliveryRetryPolicy
{
    public function decide(DeliveryFailureObservation $observation): DeliveryRetryDecision
    {
        if ($observation->providerAccepted) {
            return new DeliveryRetryDecision(
                outcomeClass: DeliveryAttemptOutcomeClass::ProviderAccepted,
                action: DeliveryRecoveryAction::MarkAccepted,
                retryAllowed: false,
                reason: 'provider_accepted',
            );
        }

        if ($observation->errorCategory === null) {
            return $this->ambiguousDecision('insufficient_failure_evidence');
        }

        if (in_array($observation->errorCategory, [
            ProviderErrorCategory::Validation,
            ProviderErrorCategory::Permanent,
        ], true)) {
            return new DeliveryRetryDecision(
                outcomeClass: DeliveryAttemptOutcomeClass::PermanentValidation,
                action: DeliveryRecoveryAction::FailOperation,
                retryAllowed: false,
                reason: 'permanent_validation',
            );
        }

        if (in_array($observation->errorCategory, [
            ProviderErrorCategory::Authentication,
            ProviderErrorCategory::Authorization,
        ], true)) {
            return new DeliveryRetryDecision(
                outcomeClass: DeliveryAttemptOutcomeClass::AuthOrPolicy,
                action: DeliveryRecoveryAction::HoldConnection,
                retryAllowed: false,
                reason: 'auth_or_policy',
            );
        }

        if ($observation->errorCategory === ProviderErrorCategory::Unknown) {
            return $this->ambiguousDecision('unknown_provider_outcome');
        }

        if (! $observation->acceptanceKnownNotOccurred) {
            return $this->ambiguousDecision(
                $observation->requestMayHaveReachedProvider
                    ? 'provider_acceptance_uncertain'
                    : 'non_acceptance_not_proven',
            );
        }

        if ($observation->errorCategory === ProviderErrorCategory::RateLimited) {
            return $this->retryDecision(
                observation: $observation,
                outcomeClass: DeliveryAttemptOutcomeClass::RateLimited,
                action: DeliveryRecoveryAction::RetryWait,
                reason: 'rate_limited',
            );
        }

        if (in_array($observation->errorCategory, [
            ProviderErrorCategory::Retryable,
            ProviderErrorCategory::Unavailable,
        ], true)) {
            $serverFailure = $observation->httpStatus !== null && $observation->httpStatus >= 500;

            return $this->retryDecision(
                observation: $observation,
                outcomeClass: $serverFailure
                    ? DeliveryAttemptOutcomeClass::TransientServer
                    : DeliveryAttemptOutcomeClass::TransientPreAccept,
                action: DeliveryRecoveryAction::RetrySameRoute,
                reason: $serverFailure ? 'transient_server' : 'transient_pre_accept',
            );
        }

        return $this->ambiguousDecision('unclassified_provider_outcome');
    }

    private function retryDecision(
        DeliveryFailureObservation $observation,
        DeliveryAttemptOutcomeClass $outcomeClass,
        DeliveryRecoveryAction $action,
        string $reason,
    ): DeliveryRetryDecision {
        if (! $observation->retryBudgetRemains()) {
            return new DeliveryRetryDecision(
                outcomeClass: $outcomeClass,
                action: DeliveryRecoveryAction::StopRetrying,
                retryAllowed: false,
                reason: 'retry_budget_exhausted',
            );
        }

        return new DeliveryRetryDecision(
            outcomeClass: $outcomeClass,
            action: $action,
            retryAllowed: true,
            reason: $reason,
            minimumDelaySeconds: $observation->minimumDelaySeconds,
            resetAt: $observation->resetAt,
        );
    }

    private function ambiguousDecision(string $reason): DeliveryRetryDecision
    {
        return new DeliveryRetryDecision(
            outcomeClass: DeliveryAttemptOutcomeClass::AmbiguousTransport,
            action: DeliveryRecoveryAction::Reconcile,
            retryAllowed: false,
            reason: $reason,
        );
    }
}
