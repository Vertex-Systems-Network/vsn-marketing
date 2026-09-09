<?php

use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationEvidence;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResolution;

it('resolves provider accepted evidence monotonically without authorizing replay', function () {
    $decision = (new DeliveryReconciliationPolicy)->decide(new DeliveryReconciliationEvidence(
        providerAccepted: true,
    ));

    expect($decision->resolution)->toBe(DeliveryReconciliationResolution::Accepted)
        ->and($decision->accepted)->toBeTrue()
        ->and($decision->retryAllowed)->toBeFalse()
        ->and($decision->operatorActionRequired)->toBeFalse()
        ->and($decision->terminal())->toBeTrue()
        ->and($decision->reason)->toBe('provider_evidence_proves_acceptance');
});

it('authorizes retry only when non acceptance and retry safety are both proven', function () {
    $decision = (new DeliveryReconciliationPolicy)->decide(new DeliveryReconciliationEvidence(
        acceptanceKnownNotOccurred: true,
        retrySafe: true,
    ));

    expect($decision->resolution)->toBe(DeliveryReconciliationResolution::NotAcceptedRetrySafe)
        ->and($decision->accepted)->toBeFalse()
        ->and($decision->retryAllowed)->toBeTrue()
        ->and($decision->operatorActionRequired)->toBeFalse()
        ->and($decision->terminal())->toBeTrue()
        ->and($decision->reason)->toBe('provider_evidence_proves_not_accepted_and_retry_safe');
});

it('requires explicit operator handling when non acceptance is proven but replay is not safe', function () {
    $decision = (new DeliveryReconciliationPolicy)->decide(new DeliveryReconciliationEvidence(
        acceptanceKnownNotOccurred: true,
        retrySafe: false,
    ));

    expect($decision->resolution)->toBe(DeliveryReconciliationResolution::OperatorResolutionRequired)
        ->and($decision->accepted)->toBeFalse()
        ->and($decision->retryAllowed)->toBeFalse()
        ->and($decision->operatorActionRequired)->toBeTrue()
        ->and($decision->terminal())->toBeTrue()
        ->and($decision->reason)->toBe('non_acceptance_proven_but_automatic_retry_not_safe');
});

it('preserves ambiguous attempts while bounded probe capacity remains', function () {
    $decision = (new DeliveryReconciliationPolicy)->decide(new DeliveryReconciliationEvidence(
        probeAttemptNumber: 1,
        maxProbeAttempts: 3,
    ));

    expect($decision->resolution)->toBe(DeliveryReconciliationResolution::Pending)
        ->and($decision->accepted)->toBeFalse()
        ->and($decision->retryAllowed)->toBeFalse()
        ->and($decision->operatorActionRequired)->toBeFalse()
        ->and($decision->terminal())->toBeFalse()
        ->and($decision->reason)->toBe('acceptance_unresolved_continue_bounded_reconciliation');
});

it('escalates unresolved ambiguity after the bounded probe budget is exhausted', function () {
    $decision = (new DeliveryReconciliationPolicy)->decide(new DeliveryReconciliationEvidence(
        probeAttemptNumber: 3,
        maxProbeAttempts: 3,
    ));

    expect($decision->resolution)->toBe(DeliveryReconciliationResolution::OperatorResolutionRequired)
        ->and($decision->accepted)->toBeFalse()
        ->and($decision->retryAllowed)->toBeFalse()
        ->and($decision->operatorActionRequired)->toBeTrue()
        ->and($decision->terminal())->toBeTrue()
        ->and($decision->reason)->toBe('acceptance_unresolved_after_probe_budget_exhausted');
});

it('is idempotent for identical ambiguous evidence', function () {
    $policy = new DeliveryReconciliationPolicy;
    $evidence = new DeliveryReconciliationEvidence(
        probeAttemptNumber: 2,
        maxProbeAttempts: 4,
    );

    $first = $policy->decide($evidence);
    $second = $policy->decide($evidence);

    expect($second)->toEqual($first)
        ->and($second->resolution)->toBe(DeliveryReconciliationResolution::Pending)
        ->and($second->retryAllowed)->toBeFalse();
});

it('never manufactures acceptance or replay permission from absent provider evidence', function () {
    $decision = (new DeliveryReconciliationPolicy)->decide(new DeliveryReconciliationEvidence);

    expect($decision->resolution)->toBe(DeliveryReconciliationResolution::Pending)
        ->and($decision->accepted)->toBeFalse()
        ->and($decision->retryAllowed)->toBeFalse()
        ->and($decision->operatorActionRequired)->toBeFalse();
});

it('rejects contradictory accepted and not accepted evidence', function () {
    expect(fn () => new DeliveryReconciliationEvidence(
        providerAccepted: true,
        acceptanceKnownNotOccurred: true,
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects retry-safe claims that are not backed by proven non acceptance', function () {
    expect(fn () => new DeliveryReconciliationEvidence(
        retrySafe: true,
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid reconciliation probe budgets', function (int $attempt, int $maximum) {
    expect(fn () => new DeliveryReconciliationEvidence(
        probeAttemptNumber: $attempt,
        maxProbeAttempts: $maximum,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'negative attempt' => [-1, 3],
    'zero maximum' => [0, 0],
    'attempt beyond maximum' => [4, 3],
]);

it('enforces decision invariants so accepted retry and operator flags cannot conflict', function () {
    expect(fn () => new DeliveryReconciliationDecision(
        resolution: DeliveryReconciliationResolution::Accepted,
        accepted: false,
        retryAllowed: false,
        operatorActionRequired: false,
        reason: 'invalid',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DeliveryReconciliationDecision(
            resolution: DeliveryReconciliationResolution::NotAcceptedRetrySafe,
            accepted: false,
            retryAllowed: false,
            operatorActionRequired: false,
            reason: 'invalid',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DeliveryReconciliationDecision(
            resolution: DeliveryReconciliationResolution::OperatorResolutionRequired,
            accepted: false,
            retryAllowed: false,
            operatorActionRequired: false,
            reason: 'invalid',
        ))->toThrow(InvalidArgumentException::class);
});
