<?php

namespace App\Modules\DeliveryEngine\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliveryCircuitBreakerDecision
{
    public function __construct(
        public DeliveryCircuitBreakerState $state,
        public bool $workHeld,
        public bool $probeAllowed,
        public bool $resetFailureCount,
        public string $reason,
        public ?DateTimeImmutable $nextProbeAt = null,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Circuit breaker decision reason must not be empty.');
        }

        if ($probeAllowed && $state !== DeliveryCircuitBreakerState::HalfOpen) {
            throw new InvalidArgumentException('Probe allowance is valid only in half-open state.');
        }

        if ($probeAllowed && $workHeld) {
            throw new InvalidArgumentException('A permitted half-open probe cannot also hold work.');
        }

        if ($state === DeliveryCircuitBreakerState::Closed && $workHeld) {
            throw new InvalidArgumentException('Closed circuit breakers cannot hold work.');
        }

        if ($state !== DeliveryCircuitBreakerState::Open && $nextProbeAt !== null) {
            throw new InvalidArgumentException('Only open circuit breakers can carry a next-probe timestamp.');
        }
    }
}
