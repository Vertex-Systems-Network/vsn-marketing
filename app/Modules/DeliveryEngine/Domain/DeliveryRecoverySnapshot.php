<?php

namespace App\Modules\DeliveryEngine\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliveryRecoverySnapshot
{
    public function __construct(
        public DeliveryOperation $operation,
        public string $operationClass,
        public DeliveryCircuitBreakerState $breakerState,
        public int $breakerConsecutiveFailures,
        public ?DateTimeImmutable $breakerNextProbeAt,
        public bool $breakerProbeInFlight,
        public int $breakerVersion,
    ) {
        if (trim($operationClass) === '') {
            throw new InvalidArgumentException('Recovery operation class must not be empty.');
        }

        if ($breakerConsecutiveFailures < 0 || $breakerVersion < 0) {
            throw new InvalidArgumentException('Circuit breaker counters must be non-negative.');
        }

        if ($breakerState !== DeliveryCircuitBreakerState::Open && $breakerNextProbeAt !== null) {
            throw new InvalidArgumentException('Only open circuit breakers may carry a next-probe timestamp.');
        }
    }
}
