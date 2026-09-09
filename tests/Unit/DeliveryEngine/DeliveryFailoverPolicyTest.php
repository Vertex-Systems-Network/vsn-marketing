<?php

use App\Modules\DeliveryEngine\Domain\DeliveryFailoverDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryFailoverPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryRouteAcceptanceState;

function eligibleFailoverDecision(
    DeliveryRouteAcceptanceState $acceptanceState = DeliveryRouteAcceptanceState::KnownNotAccepted,
    bool $sameWorkspace = true,
    bool $tenantChecksPass = true,
    bool $capabilityCompatible = true,
    bool $policyAllows = true,
    bool $connectionReady = true,
    bool $quotaAvailable = true,
    bool $breakerAllows = true,
): DeliveryFailoverDecision {
    return (new DeliveryFailoverPolicy)->decide(
        previousRouteAcceptance: $acceptanceState,
        sameWorkspace: $sameWorkspace,
        tenantChecksPass: $tenantChecksPass,
        capabilityCompatible: $capabilityCompatible,
        policyAllows: $policyAllows,
        connectionReady: $connectionReady,
        quotaAvailable: $quotaAvailable,
        breakerAllows: $breakerAllows,
    );
}

it('permits failover only after proven non acceptance and all alternate route gates pass', function () {
    $decision = eligibleFailoverDecision();

    expect($decision->eligible)->toBeTrue()
        ->and($decision->createNewAttempt)->toBeTrue()
        ->and($decision->preserveLogicalOperationIdentity)->toBeTrue()
        ->and($decision->reason)->toBe('alternate_route_eligible_after_proven_non_acceptance');
});

it('never reroutes an accepted logical operation', function () {
    $decision = eligibleFailoverDecision(acceptanceState: DeliveryRouteAcceptanceState::Accepted);

    expect($decision->eligible)->toBeFalse()
        ->and($decision->createNewAttempt)->toBeFalse()
        ->and($decision->preserveLogicalOperationIdentity)->toBeFalse()
        ->and($decision->reason)->toBe('previous_route_accepted');
});

it('never reroutes an unresolved ambiguous operation', function () {
    $decision = eligibleFailoverDecision(acceptanceState: DeliveryRouteAcceptanceState::Ambiguous);

    expect($decision->eligible)->toBeFalse()
        ->and($decision->createNewAttempt)->toBeFalse()
        ->and($decision->preserveLogicalOperationIdentity)->toBeFalse()
        ->and($decision->reason)->toBe('previous_route_acceptance_unresolved');
});

it('fails closed when an alternate route crosses the workspace boundary', function () {
    $decision = eligibleFailoverDecision(sameWorkspace: false);

    expect($decision->eligible)->toBeFalse()
        ->and($decision->reason)->toBe('alternate_route_crosses_workspace_boundary');
});

it('fails closed when any required alternate route eligibility gate fails', function (
    string $gate,
    string $reason,
) {
    $arguments = [
        'tenantChecksPass' => true,
        'capabilityCompatible' => true,
        'policyAllows' => true,
        'connectionReady' => true,
        'quotaAvailable' => true,
        'breakerAllows' => true,
    ];
    $arguments[$gate] = false;

    $decision = eligibleFailoverDecision(
        tenantChecksPass: $arguments['tenantChecksPass'],
        capabilityCompatible: $arguments['capabilityCompatible'],
        policyAllows: $arguments['policyAllows'],
        connectionReady: $arguments['connectionReady'],
        quotaAvailable: $arguments['quotaAvailable'],
        breakerAllows: $arguments['breakerAllows'],
    );

    expect($decision->eligible)->toBeFalse()
        ->and($decision->createNewAttempt)->toBeFalse()
        ->and($decision->preserveLogicalOperationIdentity)->toBeFalse()
        ->and($decision->reason)->toBe($reason);
})->with([
    ['tenantChecksPass', 'alternate_route_tenant_checks_failed'],
    ['capabilityCompatible', 'alternate_route_capability_incompatible'],
    ['policyAllows', 'alternate_route_policy_denied'],
    ['connectionReady', 'alternate_route_not_ready'],
    ['quotaAvailable', 'alternate_route_quota_unavailable'],
    ['breakerAllows', 'alternate_route_breaker_blocked'],
]);

it('gives accepted and ambiguous state precedence over alternate route eligibility', function (
    DeliveryRouteAcceptanceState $acceptanceState,
    string $reason,
) {
    $decision = eligibleFailoverDecision(
        acceptanceState: $acceptanceState,
        sameWorkspace: false,
        tenantChecksPass: false,
        capabilityCompatible: false,
        policyAllows: false,
        connectionReady: false,
        quotaAvailable: false,
        breakerAllows: false,
    );

    expect($decision->eligible)->toBeFalse()
        ->and($decision->reason)->toBe($reason);
})->with([
    [DeliveryRouteAcceptanceState::Accepted, 'previous_route_accepted'],
    [DeliveryRouteAcceptanceState::Ambiguous, 'previous_route_acceptance_unresolved'],
]);

it('is deterministic for identical proven non acceptance evidence', function () {
    $first = eligibleFailoverDecision();
    $second = eligibleFailoverDecision();

    expect($second)->toEqual($first);
});

it('enforces failover decision identity invariants', function () {
    expect(fn () => new DeliveryFailoverDecision(
        eligible: true,
        createNewAttempt: false,
        preserveLogicalOperationIdentity: true,
        reason: 'invalid',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DeliveryFailoverDecision(
            eligible: true,
            createNewAttempt: true,
            preserveLogicalOperationIdentity: false,
            reason: 'invalid',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DeliveryFailoverDecision(
            eligible: false,
            createNewAttempt: false,
            preserveLogicalOperationIdentity: false,
            reason: '   ',
        ))->toThrow(InvalidArgumentException::class);
});
