<?php

namespace App\Modules\DeliveryEngine\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliveryRetryDecision
{
    public function __construct(
        public DeliveryAttemptOutcomeClass $outcomeClass,
        public DeliveryRecoveryAction $action,
        public bool $retryAllowed,
        public string $reason,
        public ?int $minimumDelaySeconds = null,
        public ?DateTimeImmutable $resetAt = null,
    ) {
        if ($reason === '') {
            throw new InvalidArgumentException('Retry decision reason must not be empty.');
        }

        if ($minimumDelaySeconds !== null && $minimumDelaySeconds < 0) {
            throw new InvalidArgumentException('Minimum delay seconds must be non-negative.');
        }

        if (! $retryAllowed && ($minimumDelaySeconds !== null || $resetAt !== null)) {
            throw new InvalidArgumentException('Non-retry decisions cannot carry retry timing evidence.');
        }
    }
}
