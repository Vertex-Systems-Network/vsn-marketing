<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryReconciliationResult
{
    public function __construct(
        public DeliveryOperation $operation,
        public string $attemptId,
        public DeliveryReconciliationResolution $resolution,
        public bool $changed,
        public bool $retryAllowed,
        public bool $operatorActionRequired,
        public string $reason,
    ) {}
}
