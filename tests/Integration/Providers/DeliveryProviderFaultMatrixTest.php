<?php

use App\Modules\DeliveryEngine\Domain\DeliveryAttemptOutcomeClass;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerState;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryAction;
use App\Modules\DeliveryEngine\Domain\DeliveryRetryPolicy;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;

it('maps provider fault classes to fail-closed recovery decisions', function (
    DeliveryFailureObservation $observation,
    DeliveryAttemptOutcomeClass $expectedOutcome,
    DeliveryRecoveryAction $expectedAction,
    bool $retryAllowed,
) {
    $decision = (new DeliveryRetryPolicy)->decide($observation);

    expect($decision->outcomeClass)->toBe($expectedOutcome)
        ->and($decision->action)->toBe($expectedAction)
        ->and($decision->retryAllowed)->toBe($retryAllowed);
})->with([
    'timeout with unknown acceptance reconciles' => [
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Unavailable,
            requestMayHaveReachedProvider: true,
        ),
        DeliveryAttemptOutcomeClass::AmbiguousTransport,
        DeliveryRecoveryAction::Reconcile,
        false,
    ],
    'transient pre-accept failure retries same route' => [
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Retryable,
            acceptanceKnownNotOccurred: true,
        ),
        DeliveryAttemptOutcomeClass::TransientPreAccept,
        DeliveryRecoveryAction::RetrySameRoute,
        true,
    ],
    'server outage proven pre-accept retries same route' => [
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::Unavailable,
            httpStatus: 503,
            acceptanceKnownNotOccurred: true,
        ),
        DeliveryAttemptOutcomeClass::TransientServer,
        DeliveryRecoveryAction::RetrySameRoute,
        true,
    ],
    'rate-limit waits when non-acceptance is proven' => [
        new DeliveryFailureObservation(
            errorCategory: ProviderErrorCategory::RateLimited,
            httpStatus: 429,
            minimumDelaySeconds: 30,
            acceptanceKnownNotOccurred: true,
        ),
        DeliveryAttemptOutcomeClass::RateLimited,
        DeliveryRecoveryAction::RetryWait,
        true,
    ],
    'permanent provider rejection fails operation' => [
        new DeliveryFailureObservation(errorCategory: ProviderErrorCategory::Permanent),
        DeliveryAttemptOutcomeClass::PermanentValidation,
        DeliveryRecoveryAction::FailOperation,
        false,
    ],
    'authorization fault holds connection' => [
        new DeliveryFailureObservation(errorCategory: ProviderErrorCategory::Authorization),
        DeliveryAttemptOutcomeClass::AuthOrPolicy,
        DeliveryRecoveryAction::HoldConnection,
        false,
    ],
]);

it('prevents retry amplification when the attempt budget is exhausted', function () {
    $decision = (new DeliveryRetryPolicy)->decide(new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Retryable,
        acceptanceKnownNotOccurred: true,
        attemptNumber: 3,
        maxAttempts: 3,
    ));

    expect($decision->retryAllowed)->toBeFalse()
        ->and($decision->action)->toBe(DeliveryRecoveryAction::StopRetrying)
        ->and($decision->reason)->toBe('retry_budget_exhausted');
});

it('uses provider reset evidence when rate limiting opens the breaker', function () {
    $now = new DateTimeImmutable('2026-09-12T00:00:00+00:00');
    $providerResetAt = new DateTimeImmutable('2026-09-12T00:05:00+00:00');

    $decision = (new DeliveryCircuitBreakerPolicy)->afterOutcome(
        state: DeliveryCircuitBreakerState::Closed,
        outcomeClass: DeliveryAttemptOutcomeClass::RateLimited,
        consecutiveFailuresAfterOutcome: 1,
        now: $now,
        providerResetAt: $providerResetAt,
        openSeconds: 60,
    );

    expect($decision->state)->toBe(DeliveryCircuitBreakerState::Open)
        ->and($decision->workHeld)->toBeTrue()
        ->and($decision->nextProbeAt)->toBe($providerResetAt)
        ->and($decision->reason)->toBe('rate_limit_opens_breaker');
});

it('requires accepted provider evidence before a half-open breaker closes', function () {
    $policy = new DeliveryCircuitBreakerPolicy;
    $now = new DateTimeImmutable('2026-09-12T00:00:00+00:00');

    $failedProbe = $policy->afterOutcome(
        state: DeliveryCircuitBreakerState::HalfOpen,
        outcomeClass: DeliveryAttemptOutcomeClass::AmbiguousTransport,
        consecutiveFailuresAfterOutcome: 1,
        now: $now,
        openSeconds: 30,
    );
    $acceptedProbe = $policy->afterOutcome(
        state: DeliveryCircuitBreakerState::HalfOpen,
        outcomeClass: DeliveryAttemptOutcomeClass::ProviderAccepted,
        consecutiveFailuresAfterOutcome: 0,
        now: $now,
    );

    expect($failedProbe->state)->toBe(DeliveryCircuitBreakerState::Open)
        ->and($failedProbe->workHeld)->toBeTrue()
        ->and($acceptedProbe->state)->toBe(DeliveryCircuitBreakerState::Closed)
        ->and($acceptedProbe->resetFailureCount)->toBeTrue();
});
