<?php

namespace App\Modules\DeliveryEngine\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliveryRecoverySnapshot
{
    public function __construct(
        public DeliveryOperation $operation,
        public DeliveryCircuitBreakerState $breakerState,
        public int $breakerConsecutiveFailures,
        public ?DateTimeImmutable $breakerNextProbeAt,
        public bool $breakerProbeInFlight,
    ) {
        if ($breakerConsecutiveFailures < 0) {
            throw new InvalidArgumentException('Circuit breaker failure count must be non-negative.');
        }

        if ($breakerState !== DeliveryCircuitBreakerState::Open && $breakerNextProbeAt !== null) {
            throw new InvalidArgumentException('Only open circuit breakers may carry a next-probe timestamp.');
        }
    }
}
