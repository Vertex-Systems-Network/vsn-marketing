<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryOperationCreation
{
    public function __construct(
        public DeliveryOperation $operation,
        public bool $created,
    ) {}
}
