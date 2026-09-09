<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryDeadLetterPolicy
{
    public function decide(
        DeliveryRetryDecision $retryDecision,
        bool $operationExpired = false,
        bool $invariantCorruption = false,
    ): DeliveryDeadLetterDecision {
        if (
            $retryDecision->outcomeClass === DeliveryAttemptOutcomeClass::ProviderAccepted
            || $retryDecision->action === DeliveryRecoveryAction::MarkAccepted
        ) {
            return $this->notEligible(
                auditReason: 'provider_accepted_never_dead_lettered',
            );
        }

        if (
            $retryDecision->outcomeClass === DeliveryAttemptOutcomeClass::AmbiguousTransport
            || $retryDecision->action === DeliveryRecoveryAction::Reconcile
        ) {
            return $this->notEligible(
                auditReason: 'ambiguous_attempt_requires_reconciliation',
                reconciliationRequired: true,
            );
        }

        if ($invariantCorruption) {
            return $this->eligible(
                reason: DeliveryDeadLetterReason::InvariantCorruption,
                auditReason: 'fail_closed_invariant_corruption',
            );
        }

        if ($operationExpired) {
            return $this->eligible(
                reason: DeliveryDeadLetterReason::OperationExpired,
                auditReason: 'operation_expired_before_safe_execution',
            );
        }

        if (
            $retryDecision->outcomeClass === DeliveryAttemptOutcomeClass::PermanentValidation
            && $retryDecision->action === DeliveryRecoveryAction::FailOperation
            && ! $retryDecision->retryAllowed
        ) {
            return $this->eligible(
                reason: DeliveryDeadLetterReason::PermanentFailure,
                auditReason: 'permanent_failure_terminal_evidence',
            );
        }

        if (
            $retryDecision->action === DeliveryRecoveryAction::StopRetrying
            && ! $retryDecision->retryAllowed
            && in_array($retryDecision->outcomeClass, [
                DeliveryAttemptOutcomeClass::RateLimited,
                DeliveryAttemptOutcomeClass::TransientPreAccept,
                DeliveryAttemptOutcomeClass::TransientServer,
            ], true)
        ) {
            return $this->eligible(
                reason: DeliveryDeadLetterReason::RetryBudgetExhausted,
                auditReason: 'retry_safe_budget_exhausted',
            );
        }

        return $this->notEligible(
            auditReason: 'terminal_dead_letter_evidence_not_established',
        );
    }

    private function eligible(
        DeliveryDeadLetterReason $reason,
        string $auditReason,
    ): DeliveryDeadLetterDecision {
        return new DeliveryDeadLetterDecision(
            eligible: true,
            reason: $reason,
            reconciliationRequired: false,
            auditReason: $auditReason,
        );
    }

    private function notEligible(
        string $auditReason,
        bool $reconciliationRequired = false,
    ): DeliveryDeadLetterDecision {
        return new DeliveryDeadLetterDecision(
            eligible: false,
            reason: null,
            reconciliationRequired: $reconciliationRequired,
            auditReason: $auditReason,
        );
    }
}
