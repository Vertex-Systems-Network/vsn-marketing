<?php

namespace App\Modules\DeliveryEngine\Domain;

use InvalidArgumentException;

final readonly class DeliveryReconciliationEvidence
{
    public function __construct(
        public int $probeAttemptNumber,
        public bool $providerAccepted = false,
        public bool $acceptanceKnownNotOccurred = false,
        public bool $retrySafe = false,
        public string $reason = 'provider_reconciliation_probe',
    ) {
        if ($probeAttemptNumber < 1) {
            throw new InvalidArgumentException('Reconciliation probe attempt number must be at least one.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reconciliation evidence reason must not be empty.');
        }

        if ($providerAccepted && $acceptanceKnownNotOccurred) {
            throw new InvalidArgumentException('Reconciliation evidence cannot prove both acceptance and non-acceptance.');
        }

        if ($retrySafe && ! $acceptanceKnownNotOccurred) {
            throw new InvalidArgumentException('Retry-safe reconciliation evidence must prove provider non-acceptance.');
        }
    }
}
