<?php

use App\Modules\DeliveryEngine\Domain\DeliveryAttemptOutcomeClass;
use App\Modules\DeliveryEngine\Domain\DeliveryDeadLetterDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryDeadLetterPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryDeadLetterReason;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryAction;
use App\Modules\DeliveryEngine\Domain\DeliveryRetryDecision;

it('dead letters terminal permanent validation evidence', function () {
    $decision = (new DeliveryDeadLetterPolicy)->decide(new DeliveryRetryDecision(
        outcomeClass: DeliveryAttemptOutcomeClass::PermanentValidation,
        action: DeliveryRecoveryAction::FailOperation,
        retryAllowed: false,
        reason: 'permanent_validation',
    ));

    expect($decision->eligible)->toBeTrue()
        ->and($decision->reason)->toBe(DeliveryDeadLetterReason::PermanentFailure)
        ->and($decision->reconciliationRequired)->toBeFalse()
        ->and($decision->auditReason)->toBe('permanent_failure_terminal_evidence');
});

it('dead letters exhausted retry safe outcomes only after retry stops', function (DeliveryAttemptOutcomeClass $outcomeClass) {
    $decision = (new DeliveryDeadLetterPolicy)->decide(new DeliveryRetryDecision(
        outcomeClass: $outcomeClass,
        action: DeliveryRecoveryAction::StopRetrying,
        retryAllowed: false,
        reason: 'retry_budget_exhausted',
    ));

    expect($decision->eligible)->toBeTrue()
        ->and($decision->reason)->toBe(DeliveryDeadLetterReason::RetryBudgetExhausted)
        ->and($decision->reconciliationRequired)->toBeFalse()
        ->and($decision->auditReason)->toBe('retry_safe_budget_exhausted');
})->with([
    DeliveryAttemptOutcomeClass::RateLimited,
    DeliveryAttemptOutcomeClass::TransientPreAccept,
    DeliveryAttemptOutcomeClass::TransientServer,
]);

it('dead letters an expired operation before another safe execution', function () {
    $decision = (new DeliveryDeadLetterPolicy)->decide(
        retryDecision: new DeliveryRetryDecision(
            outcomeClass: DeliveryAttemptOutcomeClass::AuthOrPolicy,
            action: DeliveryRecoveryAction::HoldConnection,
            retryAllowed: false,
            reason: 'auth_or_policy',
        ),
        operationExpired: true,
    );

    expect($decision->eligible)->toBeTrue()
        ->and($decision->reason)->toBe(DeliveryDeadLetterReason::OperationExpired)
        ->and($decision->auditReason)->toBe('operation_expired_before_safe_execution');
});

it('dead letters explicit invariant corruption fail closed', function () {
    $decision = (new DeliveryDeadLetterPolicy)->decide(
        retryDecision: new DeliveryRetryDecision(
            outcomeClass: DeliveryAttemptOutcomeClass::AuthOrPolicy,
            action: DeliveryRecoveryAction::HoldConnection,
            retryAllowed: false,
            reason: 'auth_or_policy',
        ),
        invariantCorruption: true,
    );

    expect($decision->eligible)->toBeTrue()
        ->and($decision->reason)->toBe(DeliveryDeadLetterReason::InvariantCorruption)
        ->and($decision->auditReason)->toBe('fail_closed_invariant_corruption');
});

it('never dead letters accepted provider evidence even when terminal flags are present', function () {
    $decision = (new DeliveryDeadLetterPolicy)->decide(
        retryDecision: new DeliveryRetryDecision(
            outcomeClass: DeliveryAttemptOutcomeClass::ProviderAccepted,
            action: DeliveryRecoveryAction::MarkAccepted,
            retryAllowed: false,
            reason: 'provider_accepted',
        ),
        operationExpired: true,
        invariantCorruption: true,
    );

    expect($decision->eligible)->toBeFalse()
        ->and($decision->reason)->toBeNull()
        ->and($decision->reconciliationRequired)->toBeFalse()
        ->and($decision->auditReason)->toBe('provider_accepted_never_dead_lettered');
});

it('keeps ambiguous provider outcomes in reconciliation even when terminal flags are present', function () {
    $decision = (new DeliveryDeadLetterPolicy)->decide(
        retryDecision: new DeliveryRetryDecision(
            outcomeClass: DeliveryAttemptOutcomeClass::AmbiguousTransport,
            action: DeliveryRecoveryAction::Reconcile,
            retryAllowed: false,
            reason: 'provider_acceptance_uncertain',
        ),
        operationExpired: true,
        invariantCorruption: true,
    );

    expect($decision->eligible)->toBeFalse()
        ->and($decision->reason)->toBeNull()
        ->and($decision->reconciliationRequired)->toBeTrue()
        ->and($decision->auditReason)->toBe('ambiguous_attempt_requires_reconciliation');
});

it('does not dead letter auth policy holds or live retry decisions without terminal evidence', function () {
    $policy = new DeliveryDeadLetterPolicy;

    $hold = $policy->decide(new DeliveryRetryDecision(
        outcomeClass: DeliveryAttemptOutcomeClass::AuthOrPolicy,
        action: DeliveryRecoveryAction::HoldConnection,
        retryAllowed: false,
        reason: 'auth_or_policy',
    ));

    $retry = $policy->decide(new DeliveryRetryDecision(
        outcomeClass: DeliveryAttemptOutcomeClass::TransientPreAccept,
        action: DeliveryRecoveryAction::RetrySameRoute,
        retryAllowed: true,
        reason: 'transient_pre_accept',
        minimumDelaySeconds: 1,
    ));

    expect($hold->eligible)->toBeFalse()
        ->and($hold->reason)->toBeNull()
        ->and($hold->reconciliationRequired)->toBeFalse()
        ->and($retry->eligible)->toBeFalse()
        ->and($retry->reason)->toBeNull()
        ->and($retry->reconciliationRequired)->toBeFalse();
});

it('does not reinterpret a non retry safe stopped outcome as exhausted retry budget', function () {
    $decision = (new DeliveryDeadLetterPolicy)->decide(new DeliveryRetryDecision(
        outcomeClass: DeliveryAttemptOutcomeClass::AuthOrPolicy,
        action: DeliveryRecoveryAction::StopRetrying,
        retryAllowed: false,
        reason: 'stopped',
    ));

    expect($decision->eligible)->toBeFalse()
        ->and($decision->reason)->toBeNull()
        ->and($decision->auditReason)->toBe('terminal_dead_letter_evidence_not_established');
});

it('enforces dead letter decision evidence invariants', function () {
    expect(fn () => new DeliveryDeadLetterDecision(
        eligible: true,
        reason: null,
        reconciliationRequired: false,
        auditReason: 'invalid',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DeliveryDeadLetterDecision(
            eligible: false,
            reason: DeliveryDeadLetterReason::PermanentFailure,
            reconciliationRequired: false,
            auditReason: 'invalid',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DeliveryDeadLetterDecision(
            eligible: true,
            reason: DeliveryDeadLetterReason::InvariantCorruption,
            reconciliationRequired: true,
            auditReason: 'invalid',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DeliveryDeadLetterDecision(
            eligible: false,
            reason: null,
            reconciliationRequired: false,
            auditReason: '   ',
        ))->toThrow(InvalidArgumentException::class);
});
