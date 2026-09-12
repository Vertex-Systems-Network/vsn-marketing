<?php

use App\Modules\DeliveryEngine\Domain\DeliveryAttemptOutcomeClass;
use App\Modules\DeliveryEngine\Domain\DeliveryFailoverPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryAction;
use App\Modules\DeliveryEngine\Domain\DeliveryRetryPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryRouteAcceptanceState;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;

it('certifies provider-neutral timeout ambiguity never becomes a blind retry', function () {
    $policy = new DeliveryRetryPolicy;

    $first = $policy->decide(new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Unavailable,
        requestMayHaveReachedProvider: true,
    ));
    $second = $policy->decide(new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Unavailable,
        requestMayHaveReachedProvider: true,
    ));

    expect($first->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::AmbiguousTransport)
        ->and($first->action)->toBe(DeliveryRecoveryAction::Reconcile)
        ->and($first->retryAllowed)->toBeFalse()
        ->and($first->reason)->toBe('provider_acceptance_uncertain')
        ->and($second->outcomeClass)->toBe($first->outcomeClass)
        ->and($second->action)->toBe($first->action)
        ->and($second->reason)->toBe($first->reason);
});

it('certifies shared provider taxonomy drives bounded retry and rate-limit decisions', function () {
    $policy = new DeliveryRetryPolicy;
    $resetAt = new DateTimeImmutable('2026-09-12T00:05:00+00:00');

    $transient = $policy->decide(new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::Unavailable,
        httpStatus: 503,
        acceptanceKnownNotOccurred: true,
        attemptNumber: 1,
        maxAttempts: 3,
    ));
    $rateLimited = $policy->decide(new DeliveryFailureObservation(
        errorCategory: ProviderErrorCategory::RateLimited,
        minimumDelaySeconds: 30,
        resetAt: $resetAt,
        acceptanceKnownNotOccurred: true,
        attemptNumber: 1,
        maxAttempts: 3,
    ));

    expect($transient->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::TransientServer)
        ->and($transient->action)->toBe(DeliveryRecoveryAction::RetrySameRoute)
        ->and($transient->retryAllowed)->toBeTrue()
        ->and($rateLimited->outcomeClass)->toBe(DeliveryAttemptOutcomeClass::RateLimited)
        ->and($rateLimited->action)->toBe(DeliveryRecoveryAction::RetryWait)
        ->and($rateLimited->retryAllowed)->toBeTrue()
        ->and($rateLimited->minimumDelaySeconds)->toBe(30)
        ->and($rateLimited->resetAt)->toBe($resetAt);
});

it('certifies retry budget exhaustion prevents provider retry amplification', function () {
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

it('certifies provider failover is allowed only after proven non-acceptance and all route gates pass', function () {
    $decision = (new DeliveryFailoverPolicy)->decide(
        previousRouteAcceptance: DeliveryRouteAcceptanceState::KnownNotAccepted,
        sameWorkspace: true,
        tenantChecksPass: true,
        capabilityCompatible: true,
        policyAllows: true,
        connectionReady: true,
        quotaAvailable: true,
        breakerAllows: true,
    );

    expect($decision->eligible)->toBeTrue()
        ->and($decision->createNewAttempt)->toBeTrue()
        ->and($decision->preserveLogicalOperationIdentity)->toBeTrue()
        ->and($decision->reason)->toBe('alternate_route_eligible_after_proven_non_acceptance');
});

it('certifies accepted and ambiguous prior routes cannot fail over', function (DeliveryRouteAcceptanceState $acceptance, string $reason) {
    $decision = (new DeliveryFailoverPolicy)->decide(
        previousRouteAcceptance: $acceptance,
        sameWorkspace: true,
        tenantChecksPass: true,
        capabilityCompatible: true,
        policyAllows: true,
        connectionReady: true,
        quotaAvailable: true,
        breakerAllows: true,
    );

    expect($decision->eligible)->toBeFalse()
        ->and($decision->createNewAttempt)->toBeFalse()
        ->and($decision->preserveLogicalOperationIdentity)->toBeFalse()
        ->and($decision->reason)->toBe($reason);
})->with([
    'accepted route' => [DeliveryRouteAcceptanceState::Accepted, 'previous_route_accepted'],
    'ambiguous route' => [DeliveryRouteAcceptanceState::Ambiguous, 'previous_route_acceptance_unresolved'],
]);

it('certifies every alternate route eligibility boundary fails closed independently', function (string $gate, string $reason) {
    $gates = [
        'sameWorkspace' => true,
        'tenantChecksPass' => true,
        'capabilityCompatible' => true,
        'policyAllows' => true,
        'connectionReady' => true,
        'quotaAvailable' => true,
        'breakerAllows' => true,
    ];
    $gates[$gate] = false;

    $decision = (new DeliveryFailoverPolicy)->decide(
        previousRouteAcceptance: DeliveryRouteAcceptanceState::KnownNotAccepted,
        sameWorkspace: $gates['sameWorkspace'],
        tenantChecksPass: $gates['tenantChecksPass'],
        capabilityCompatible: $gates['capabilityCompatible'],
        policyAllows: $gates['policyAllows'],
        connectionReady: $gates['connectionReady'],
        quotaAvailable: $gates['quotaAvailable'],
        breakerAllows: $gates['breakerAllows'],
    );

    expect($decision->eligible)->toBeFalse()
        ->and($decision->createNewAttempt)->toBeFalse()
        ->and($decision->reason)->toBe($reason);
})->with([
    'workspace boundary' => ['sameWorkspace', 'alternate_route_crosses_workspace_boundary'],
    'tenant checks' => ['tenantChecksPass', 'alternate_route_tenant_checks_failed'],
    'capability mismatch' => ['capabilityCompatible', 'alternate_route_capability_incompatible'],
    'policy denial' => ['policyAllows', 'alternate_route_policy_denied'],
    'connection not ready' => ['connectionReady', 'alternate_route_not_ready'],
    'quota unavailable' => ['quotaAvailable', 'alternate_route_quota_unavailable'],
    'breaker blocked' => ['breakerAllows', 'alternate_route_breaker_blocked'],
]);
