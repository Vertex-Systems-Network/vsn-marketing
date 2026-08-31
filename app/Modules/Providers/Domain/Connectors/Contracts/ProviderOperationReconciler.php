<?php

namespace App\Modules\Providers\Domain\Connectors\Contracts;

use App\Modules\Providers\Domain\Connectors\ProviderOperation;
use App\Modules\Providers\Domain\Connectors\ProviderOperationObservation;

interface ProviderOperationReconciler
{
    public function reconcile(
        ProviderOperation $operation,
        ProviderOperationObservation $observation,
    ): ProviderOperation;
}
