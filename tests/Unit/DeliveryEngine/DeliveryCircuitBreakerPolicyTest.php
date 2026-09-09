<?php

use App\Modules\DeliveryEngine\Domain\DeliveryAttemptOutcomeClass;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerKey;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerPolicy;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerState;
use DateTimeImmutable;

it('derives stable breaker identity from workspace connection and operation class without tenant leakage', function () {
    $first = new DeliveryCircuitBreakerKey('workspace-a', 'connection-1', 'email.send');
    $same = new DeliveryCircuitBreakerKey('workspace-a', 'connection-1', 'email.send');
    $otherWorkspace = new DeliveryCircuitBreakerKey('workspace-b', 'connection-1', 'email.send');
    $otherConnection = new DeliveryCircuitBreakerKey('workspace-a', 'connection-2', 'email.send');
    $otherOperation = new DeliveryCircuitBreakerKey('workspace-a', 'connection-1', 'email.transactional');

    expect($first->fingerprint())->toBe($same->fingerprint())
        ->and($first->fingerprint())->not->toBe($otherWorkspace->fingerprint())
        ->and($first->fingerprint())->not->toBe($otherConnection->fingerprint())
        ->and($first->fingerprint())->not->toBe($otherOperation->fingerprint());
});

it('rejects incomplete breaker scope', function (array $scope) {
    expect(fn () => new DeliveryCircuitBreakerKey(...$scope))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'workspace' => [[
        'workspaceId' => ' ',
        'providerConnectionId' => 'connection-1',
        'operationClass' => 'email.send',
    ]],
    'provider connection' => [[
        'workspaceId' => 'workspace-a',
        'providerConnectionId' => '',
        'operationClass' => 'email.send',
    ]],
    'operation class' => [[
        'workspaceId' => 'workspace-a',
        'providerConnectionId' => 'connection-1',
        'operationClass' => "\t",
    ]],
]);

it('allows normal work while closed', function () {
    $decision = (new DeliveryCircuitBreakerPolicy)->beforeAttempt(
        state: DeliveryCircuitBreakerState::Closed,
        now: new DateTimeImmutable('2026-09-10T00:00:00+00:00'),
    );

    expect($decision->state)->toBe(DeliveryCircuitBreakerState::Closed)
        ->and($decision->workHeld)->toBeFalse()
        ->and($decision->probeAllowed)->toBeFalse()
        ->and($decision->reason)->toBe('breaker_closed');
});

it('holds open work until cooldown and then permits one half open probe', function () {
    $policy = new DeliveryCircuitBreakerPolicy;
    $nextProbeAt = new DateTimeImmutable('2026-09-10T00:01:00+00:00');

    $held = $policy->beforeAttempt(
        state: DeliveryCircuitBreakerState::Open,
        now: new DateTimeImmutable('2026-09-10T00:00:59+00:00'),
        nextProbeAt: $nextProbeAt,
    );

    $probe = $policy->beforeAttempt(
        state: DeliveryCircuitBreakerState::Open,
        now: $nextProbeAt,
        nextProbeAt: $nextProbeAt,
    );

    expect($held->state)->toBe(DeliveryCircuitBreakerState::Open)
        ->and($held->workHeld)->toBeTrue()
        ->and($held->nextProbeAt)->toBe($nextProbeAt)
        ->and($probe->state)->toBe(DeliveryCircuitBreakerState::HalfOpen)
        ->and($probe->workHeld)->toBeFalse()
        ->and($probe->probeAllowed)->toBeTrue();
});

it('holds additional half open work while a probe is in flight', function () {
    $decision = (new DeliveryCircuitBreakerPolicy)->beforeAttempt(
        state: DeliveryCircuitBreakerState::HalfOpen,
        now: new DateTimeImmutable('2026-09-10T00:00:00+00:00'),
        probeInFlight: true,
    );

    expect($decision->state)->toBe(DeliveryCircuitBreakerState::HalfOpen)
        ->and($decision->workHeld)->toBeTrue()
        ->and($decision->probeAllowed)->toBeFalse()
        ->and($decision->reason)->toBe('half_open_probe_in_flight');
});

it('opens only after the explicit sustained failure threshold', function () {
    $policy = new DeliveryCircuitBreakerPolicy;
    $now = new DateTimeImmutable('2026-09-10T00:00:00+00:00');

    $below = $policy->afterOutcome(
        state: DeliveryCircuitBreakerState::Closed,
        outcomeClass: DeliveryAttemptOutcomeClass::TransientServer,
        consecutiveFailuresAfterOutcome: 2,
        now: $now,
        failureThreshold: 3,
        openSeconds: 45,
    );

    $open = $policy->afterOutcome(
        state: DeliveryCircuitBreakerState::Closed,
        outcomeClass: DeliveryAttemptOutcomeClass::TransientServer,
        consecutiveFailuresAfterOutcome: 3,
        now: $now,
        failureThreshold: 3,
        openSeconds: 45,
    );

    expect($below->state)->toBe(DeliveryCircuitBreakerState::Closed)
        ->and($below->workHeld)->toBeFalse()
        ->and($open->state)->toBe(DeliveryCircuitBreakerState::Open)
        ->and($open->workHeld)->toBeTrue()
        ->and($open->reason)->toBe('failure_threshold_reached')
        ->and($open->nextProbeAt?->format(DATE_ATOM))->toBe('2026-09-10T00:00:45+00:00');
});

it('opens immediately for auth policy failures without automatic probing', function () {
    $decision = (new DeliveryCircuitBreakerPolicy)->afterOutcome(
        state: DeliveryCircuitBreakerState::Closed,
        outcomeClass: DeliveryAttemptOutcomeClass::AuthOrPolicy,
        consecutiveFailuresAfterOutcome: 1,
        now: new DateTimeImmutable('2026-09-10T00:00:00+00:00'),
    );

    expect($decision->state)->toBe(DeliveryCircuitBreakerState::Open)
        ->and($decision->workHeld)->toBeTrue()
        ->and($decision->nextProbeAt)->toBeNull()
        ->and($decision->reason)->toBe('auth_or_policy_holds_connection');
});

it('honors provider rate reset evidence when it is later than local cooldown', function () {
    $resetAt = new DateTimeImmutable('2026-09-10T00:05:00+00:00');

    $decision = (new DeliveryCircuitBreakerPolicy)->afterOutcome(
        state: DeliveryCircuitBreakerState::Closed,
        outcomeClass: DeliveryAttemptOutcomeClass::RateLimited,
        consecutiveFailuresAfterOutcome: 1,
        now: new DateTimeImmutable('2026-09-10T00:00:00+00:00'),
        providerResetAt: $resetAt,
        openSeconds: 60,
    );

    expect($decision->state)->toBe(DeliveryCircuitBreakerState::Open)
        ->and($decision->workHeld)->toBeTrue()
        ->and($decision->nextProbeAt)->toBe($resetAt)
        ->and($decision->reason)->toBe('rate_limit_opens_breaker');
});

it('reopens a failed half open probe including ambiguous transport evidence', function (DeliveryAttemptOutcomeClass $outcomeClass) {
    $decision = (new DeliveryCircuitBreakerPolicy)->afterOutcome(
        state: DeliveryCircuitBreakerState::HalfOpen,
        outcomeClass: $outcomeClass,
        consecutiveFailuresAfterOutcome: 1,
        now: new DateTimeImmutable('2026-09-10T00:00:00+00:00'),
        openSeconds: 30,
    );

    expect($decision->state)->toBe(DeliveryCircuitBreakerState::Open)
        ->and($decision->workHeld)->toBeTrue()
        ->and($decision->reason)->toBe('half_open_probe_failed');
})->with([
    DeliveryAttemptOutcomeClass::TransientPreAccept,
    DeliveryAttemptOutcomeClass::TransientServer,
    DeliveryAttemptOutcomeClass::AmbiguousTransport,
]);

it('closes and resets failure streak only on accepted provider evidence', function () {
    $decision = (new DeliveryCircuitBreakerPolicy)->afterOutcome(
        state: DeliveryCircuitBreakerState::HalfOpen,
        outcomeClass: DeliveryAttemptOutcomeClass::ProviderAccepted,
        consecutiveFailuresAfterOutcome: 0,
        now: new DateTimeImmutable('2026-09-10T00:00:00+00:00'),
    );

    expect($decision->state)->toBe(DeliveryCircuitBreakerState::Closed)
        ->and($decision->workHeld)->toBeFalse()
        ->and($decision->resetFailureCount)->toBeTrue()
        ->and($decision->reason)->toBe('provider_acceptance_closes_breaker');
});

it('does not poison a closed connection with operation specific validation failure', function () {
    $decision = (new DeliveryCircuitBreakerPolicy)->afterOutcome(
        state: DeliveryCircuitBreakerState::Closed,
        outcomeClass: DeliveryAttemptOutcomeClass::PermanentValidation,
        consecutiveFailuresAfterOutcome: 1,
        now: new DateTimeImmutable('2026-09-10T00:00:00+00:00'),
    );

    expect($decision->state)->toBe(DeliveryCircuitBreakerState::Closed)
        ->and($decision->workHeld)->toBeFalse()
        ->and($decision->reason)->toBe('permanent_validation_does_not_affect_connection_health');
});

it('fails closed on invalid breaker configuration or contradictory decisions', function () {
    $policy = new DeliveryCircuitBreakerPolicy;
    $now = new DateTimeImmutable('2026-09-10T00:00:00+00:00');

    expect(fn () => $policy->afterOutcome(
        state: DeliveryCircuitBreakerState::Closed,
        outcomeClass: DeliveryAttemptOutcomeClass::TransientServer,
        consecutiveFailuresAfterOutcome: -1,
        now: $now,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $policy->afterOutcome(
            state: DeliveryCircuitBreakerState::Closed,
            outcomeClass: DeliveryAttemptOutcomeClass::TransientServer,
            consecutiveFailuresAfterOutcome: 1,
            now: $now,
            failureThreshold: 0,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DeliveryCircuitBreakerDecision(
            state: DeliveryCircuitBreakerState::Closed,
            workHeld: true,
            probeAllowed: false,
            resetFailureCount: false,
            reason: 'invalid',
        ))->toThrow(InvalidArgumentException::class);
});
