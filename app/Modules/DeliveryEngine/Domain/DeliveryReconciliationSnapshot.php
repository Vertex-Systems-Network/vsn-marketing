<?php

namespace App\Modules\DeliveryEngine\Domain;

use InvalidArgumentException;

final readonly class DeliveryReconciliationSnapshot
{
    public function __construct(
        public DeliveryOperation $operation,
        public string $attemptId,
        public DeliveryReconciliationResolution $resolution,
        public bool $providerAccepted,
        public bool $acceptanceKnownNotOccurred,
        public bool $retrySafe,
        public int $probeAttemptNumber,
        public int $maxProbeAttempts,
        public bool $operatorActionRequired,
        public string $reason,
    ) {
        if (trim($attemptId) === '' || trim($reason) === '') {
            throw new InvalidArgumentException('Reconciliation snapshot identifiers and reason must not be empty.');
        }

        if ($probeAttemptNumber < 0 || $maxProbeAttempts < 1 || $probeAttemptNumber > $maxProbeAttempts) {
            throw new InvalidArgumentException('Reconciliation snapshot probe counters are invalid.');
        }
    }
}
