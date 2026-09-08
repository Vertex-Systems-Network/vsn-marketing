<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryAdmissionResult
{
    public function __construct(
        public DeliveryOperation $operation,
        public bool $admitted,
        public ?string $providerId,
        public ?string $providerConnectionId,
        public ?string $backpressureReason,
    ) {}
}
