<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class DeliveryOperationScheduleResult
{
    public function __construct(
        public DeliveryOperation $operation,
        public bool $created,
    ) {}
}
