<?php

namespace App\Modules\Providers\Domain\Connectors;

use App\Modules\Providers\Domain\Connectors\Contracts\ProviderOperationReconciler;

final readonly class DefaultProviderOperationReconciler implements ProviderOperationReconciler
{
    public function reconcile(
        ProviderOperation $operation,
        ProviderOperationObservation $observation,
    ): ProviderOperation
    {
        return $operation->withObservation($observation);
    }
}
