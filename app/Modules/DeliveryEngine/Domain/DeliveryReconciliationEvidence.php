<?php

namespace App\Modules\DeliveryEngine\Domain;

use InvalidArgumentException;

final readonly class DeliveryReconciliationEvidence
{
    public function __construct(
        public bool $providerAccepted = false,
        public bool $acceptanceKnownNotOccurred = false,
        public bool $retrySafe = false,
        public int $probeAttemptNumber = 0,
        public int $maxProbeAttempts = 3,
    ) {
        if ($providerAccepted && $acceptanceKnownNotOccurred) {
            throw new InvalidArgumentException('Reconciliation evidence cannot prove both accepted and not accepted.');
        }

        if ($retrySafe && ! $acceptanceKnownNotOccurred) {
            throw new InvalidArgumentException('Retry-safe reconciliation evidence requires proven non-acceptance.');
        }

        if ($probeAttemptNumber < 0) {
            throw new InvalidArgumentException('Reconciliation probe attempt number must be non-negative.');
        }

        if ($maxProbeAttempts < 1) {
            throw new InvalidArgumentException('Reconciliation maximum probe attempts must be at least one.');
        }

        if ($probeAttemptNumber > $maxProbeAttempts) {
            throw new InvalidArgumentException('Reconciliation probe attempts cannot exceed the configured maximum.');
        }
    }

    public function probeBudgetRemains(): bool
    {
        return $this->probeAttemptNumber < $this->maxProbeAttempts;
    }
}
