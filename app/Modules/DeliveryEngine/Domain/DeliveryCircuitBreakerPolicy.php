<?php

namespace App\Modules\DeliveryEngine\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class DeliveryCircuitBreakerPolicy
{
    public function beforeAttempt(
        DeliveryCircuitBreakerState $state,
        DateTimeImmutable $now,
        ?DateTimeImmutable $nextProbeAt = null,
        bool $probeInFlight = false,
    ): DeliveryCircuitBreakerDecision {
        return match ($state) {
            DeliveryCircuitBreakerState::Closed => new DeliveryCircuitBreakerDecision(
                state: DeliveryCircuitBreakerState::Closed,
                workHeld: false,
                probeAllowed: false,
                resetFailureCount: false,
                reason: 'breaker_closed',
            ),
            DeliveryCircuitBreakerState::Open => $this->decideOpenAdmission($now, $nextProbeAt),
            DeliveryCircuitBreakerState::HalfOpen => $probeInFlight
                ? new DeliveryCircuitBreakerDecision(
                    state: DeliveryCircuitBreakerState::HalfOpen,
                    workHeld: true,
                    probeAllowed: false,
                    resetFailureCount: false,
                    reason: 'half_open_probe_in_flight',
                )
                : new DeliveryCircuitBreakerDecision(
                    state: DeliveryCircuitBreakerState::HalfOpen,
                    workHeld: false,
                    probeAllowed: true,
                    resetFailureCount: false,
                    reason: 'half_open_probe_allowed',
                ),
        };
    }

    public function afterOutcome(
        DeliveryCircuitBreakerState $state,
        DeliveryAttemptOutcomeClass $outcomeClass,
        int $consecutiveFailuresAfterOutcome,
        DateTimeImmutable $now,
        ?DateTimeImmutable $providerResetAt = null,
        int $failureThreshold = 3,
        int $openSeconds = 60,
    ): DeliveryCircuitBreakerDecision {
        if ($consecutiveFailuresAfterOutcome < 0) {
            throw new InvalidArgumentException('Circuit breaker failure count must be non-negative.');
        }

        if ($failureThreshold < 1) {
            throw new InvalidArgumentException('Circuit breaker failure threshold must be at least one.');
        }

        if ($openSeconds < 1) {
            throw new InvalidArgumentException('Circuit breaker open duration must be at least one second.');
        }

        if ($outcomeClass === DeliveryAttemptOutcomeClass::ProviderAccepted) {
            return new DeliveryCircuitBreakerDecision(
                state: DeliveryCircuitBreakerState::Closed,
                workHeld: false,
                probeAllowed: false,
                resetFailureCount: true,
                reason: 'provider_acceptance_closes_breaker',
            );
        }

        if ($outcomeClass === DeliveryAttemptOutcomeClass::PermanentValidation) {
            if ($state === DeliveryCircuitBreakerState::HalfOpen) {
                return $this->openForCooldown($now, $openSeconds, 'half_open_probe_inconclusive');
            }

            return new DeliveryCircuitBreakerDecision(
                state: $state,
                workHeld: $state === DeliveryCircuitBreakerState::Open,
                probeAllowed: false,
                resetFailureCount: false,
                reason: 'permanent_validation_does_not_affect_connection_health',
            );
        }

        if ($outcomeClass === DeliveryAttemptOutcomeClass::AuthOrPolicy) {
            return new DeliveryCircuitBreakerDecision(
                state: DeliveryCircuitBreakerState::Open,
                workHeld: true,
                probeAllowed: false,
                resetFailureCount: false,
                reason: 'auth_or_policy_holds_connection',
            );
        }

        if ($outcomeClass === DeliveryAttemptOutcomeClass::RateLimited) {
            $cooldownAt = $now->add(new DateInterval('PT'.$openSeconds.'S'));
            $nextProbeAt = $providerResetAt !== null && $providerResetAt > $cooldownAt
                ? $providerResetAt
                : $cooldownAt;

            return new DeliveryCircuitBreakerDecision(
                state: DeliveryCircuitBreakerState::Open,
                workHeld: true,
                probeAllowed: false,
                resetFailureCount: false,
                reason: 'rate_limit_opens_breaker',
                nextProbeAt: $nextProbeAt,
            );
        }

        if ($state === DeliveryCircuitBreakerState::HalfOpen) {
            return $this->openForCooldown($now, $openSeconds, 'half_open_probe_failed');
        }

        if ($state === DeliveryCircuitBreakerState::Open) {
            return $this->openForCooldown($now, $openSeconds, 'breaker_remains_open_after_failure');
        }

        if ($consecutiveFailuresAfterOutcome >= $failureThreshold) {
            return $this->openForCooldown($now, $openSeconds, 'failure_threshold_reached');
        }

        return new DeliveryCircuitBreakerDecision(
            state: DeliveryCircuitBreakerState::Closed,
            workHeld: false,
            probeAllowed: false,
            resetFailureCount: false,
            reason: 'failure_below_threshold',
        );
    }

    private function decideOpenAdmission(
        DateTimeImmutable $now,
        ?DateTimeImmutable $nextProbeAt,
    ): DeliveryCircuitBreakerDecision {
        if ($nextProbeAt === null || $now < $nextProbeAt) {
            return new DeliveryCircuitBreakerDecision(
                state: DeliveryCircuitBreakerState::Open,
                workHeld: true,
                probeAllowed: false,
                resetFailureCount: false,
                reason: $nextProbeAt === null ? 'breaker_open_manual_recovery_required' : 'breaker_open_cooldown_active',
                nextProbeAt: $nextProbeAt,
            );
        }

        return new DeliveryCircuitBreakerDecision(
            state: DeliveryCircuitBreakerState::HalfOpen,
            workHeld: false,
            probeAllowed: true,
            resetFailureCount: false,
            reason: 'breaker_cooldown_elapsed',
        );
    }

    private function openForCooldown(
        DateTimeImmutable $now,
        int $openSeconds,
        string $reason,
    ): DeliveryCircuitBreakerDecision {
        return new DeliveryCircuitBreakerDecision(
            state: DeliveryCircuitBreakerState::Open,
            workHeld: true,
            probeAllowed: false,
            resetFailureCount: false,
            reason: $reason,
            nextProbeAt: $now->add(new DateInterval('PT'.$openSeconds.'S')),
        );
    }
}
