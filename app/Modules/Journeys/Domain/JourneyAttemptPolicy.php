<?php

namespace App\Modules\Journeys\Domain;

final class JourneyAttemptPolicy
{
    public function __construct(
        public readonly int $maxWorkspaceConcurrent,
        public readonly int $leaseSeconds = 60,
        public readonly int $maxAttempts = 3,
        public readonly int $retryDelaySeconds = 30,
    ) {
        if ($maxWorkspaceConcurrent < 1 || $maxWorkspaceConcurrent > 100000 || $leaseSeconds < 1 || $leaseSeconds > 3600 || $maxAttempts < 1 || $maxAttempts > 20 || $retryDelaySeconds < 0 || $retryDelaySeconds > 86400) {
            throw new JourneyDefinitionException('invalid_attempt_policy', '$.attempt_policy');
        }
    }
}
