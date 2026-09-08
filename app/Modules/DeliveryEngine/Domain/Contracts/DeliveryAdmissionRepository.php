<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

use App\Modules\DeliveryEngine\Domain\DeliveryAdmissionResult;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;

interface DeliveryAdmissionRepository
{
    public function admit(DeliveryOperation $operation, string $providerOperation): DeliveryAdmissionResult;
}
