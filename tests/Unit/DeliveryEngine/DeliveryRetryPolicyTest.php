<?php

use App\Modules\DeliveryEngine\Domain\DeliveryAttemptOutcomeClass;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryAction;
use App\Modules\DeliveryEngine\Domain\DeliveryRetryDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryRetryPolicy;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use DateTimeImmutable;

it('marks accepted provider evidence as monotonic and non retryable', function () {
    $decision = (new DeliveryRetryPolicy)->decide(new DeliveryFailureObservation(
        errorCategory: null,
        providerAccepted: true,
    ));

    expect($decision->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::ProviderAccepted)
        ->and($decision->action)->toBe(DeliveryRecoveryAction::MarkAccepted)
        ->and($decision->retryAllowed)->toBeFalse();
});

it('fails permanent validation without retrying', function (ProviderErrorCategory $category) {
    $decision = (new DeliveryRetryPolicy)->decide(new DeliveryFailureObservation(
        errorCategory: $category,
    ));

    expect($decision->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::PermanentValidation)
        ->and($decision->action)->toBe(DeliveryRecoveryAction::FailOperation)
        ->and($decision->retryAllowed)->toBeFalse();
})->with([
    ProviderErrorCategory::Validation,
    ProviderErrorCategory::Permanent,
]);

it('holds the provider connection on authentication or authorization policy failures', function (ProviderErrorCategory $category) {
    $decision = (new DeliveryRetryPolicy)->decide(new DeliveryFailureObservation(
        errorCategory: $category,
    ));

    expect($decision->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::AuthOrPolicy)
        ->and($decision->action)->toBe(DeliveryRecoveryAction::HoldConnection)
        ->and($decision->retryAllowed)->toBeFalse();
})->with([
    ProviderErrorCategory::Authentication,
    ProviderErrorCategory::Authorization,
]);

it('waits on a proven non accepted rate limit and preserves timing evidence', function () {
    $resetAt = new DateTimeImmutable('2026-09-10T12:00:00+00:00');

    $decision = (new DeliveryRetryPolicy)->decide(new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::RateLimited,
        httpStatus: 429,
        minimumDelaySeconds: 45,
        resetAt: $resetAt,
        acceptanceKnownNotOccurred: true,
        attemptNumber: 1,
        maxAttempts: 3,
    ));

    expect($decision->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::RateLimited)
        ->and($decision->action)->toBe(DeliveryRecoveryAction::RetryWait)
        ->and($decision->retryAllowed)->toBeTrue()
        ->and($decision->minimumDelaySeconds)->toBe(45)
        ->and($decision->resetAt)->toBe($resetAt);
});

it('retries a proven pre acceptance transient failure only on the same route', function () {
    $decision = (new DeliveryRetryPolicy)->decide(new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Retryable,
        acceptanceKnownNotOccurred: true,
        attemptNumber: 1,
        maxAttempts: 2,
    ));

    expect($decision->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::TransientPreAccept)
        ->and($decision->action)->toBe(DeliveryRecoveryAction::RetrySameRoute)
        ->and($decision->retryAllowed)->toBeTrue();
});

it('classifies a proven non accepted server failure separately from transport failure', function () {
    $decision = (new DeliveryRetryPolicy)->decide(new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Unavailable,
        httpStatus: 503,
        acceptanceKnownNotOccurred: true,
        attemptNumber: 1,
        maxAttempts: 2,
    ));

    expect($decision->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::TransientServer)
        ->and($decision->action)->toBe(DeliveryRecoveryAction::RetrySameRoute)
        ->and($decision->retryAllowed)->toBeTrue();
});

it('fails closed into reconciliation when acceptance is uncertain', function (DeliveryFailureObservation $observation) {
    $decision = (new DeliveryRetryPolicy)->decide($observation);

    expect($decision->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::AmbiguousTransport)
        ->and($decision->action)->toBe(DeliveryRecoveryAction::Reconcile)
        ->and($decision->retryAllowed)->toBeFalse();
})->with([
    'transport may have reached provider' => new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Retryable,
        requestMayHaveReachedProvider: true,
    ),
    'non acceptance is not proven' => new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::RateLimited,
    ),
    'unknown provider outcome' => new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Unknown,
    ),
    'missing normalized evidence' => new DeliveryFailureObservation(
        errorCategory: null,
    ),
]);

it('stops retrying when the bounded attempt budget is exhausted', function () {
    $decision = (new DeliveryRetryPolicy)->decide(new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Retryable,
        acceptanceKnownNotOccurred: true,
        attemptNumber: 3,
        maxAttempts: 3,
    ));

    expect($decision->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::TransientPreAccept)
        ->and($decision->action)->toBe(DeliveryRecoveryAction::StopRetrying)
        ->and($decision->retryAllowed)->toBeFalse()
        ->and($decision->reason)->toBe('retry_budget_exhausted');
});

it('fails closed on contradictory or invalid attempt evidence', function (array $input) {
    expect(fn () => new DeliveryFailureObservation(...$input))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'invalid HTTP status' => [[
        'errorCategory' => ProviderErrorCategory::Retryable,
        'httpStatus' => 700,
    ]],
    'negative delay' => [[
        'errorCategory' => ProviderErrorCategory::RateLimited,
        'minimumDelaySeconds' => -1,
    ]],
    'zero attempt' => [[
        'errorCategory' => ProviderErrorCategory::Retryable,
        'attemptNumber' => 0,
    ]],
    'accepted and failed' => [[
        'errorCategory' => ProviderErrorCategory::Permanent,
        'providerAccepted' => true,
    ]],
]);

it('rejects timing evidence on a non retry decision', function () {
    expect(fn () => new DeliveryRetryDecision(
        outcomeClass: DeliveryAttemptOutcomeClass::PermanentValidation,
        action: DeliveryRecoveryAction::FailOperation,
        retryAllowed: false,
        reason: 'permanent_validation',
        minimumDelaySeconds: 1,
    ))->toThrow(InvalidArgumentException::class);
});
