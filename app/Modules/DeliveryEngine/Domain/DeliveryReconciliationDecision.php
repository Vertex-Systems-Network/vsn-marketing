<?php

namespace App\Modules\DeliveryEngine\Domain;

use InvalidArgumentException;

final readonly class DeliveryReconciliationDecision
{
    public function __construct(
        public DeliveryReconciliationResolution $resolution,
        public bool $accepted,
        public bool $retryAllowed,
        public bool $operatorActionRequired,
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reconciliation decision reason must not be empty.');
        }

        if ($accepted !== ($resolution === DeliveryReconciliationResolution::Accepted)) {
            throw new InvalidArgumentException('Accepted reconciliation state must match the accepted resolution.');
        }

        if ($retryAllowed !== ($resolution === DeliveryReconciliationResolution::NotAcceptedRetrySafe)) {
            throw new InvalidArgumentException('Retry allowance requires a proven not-accepted retry-safe resolution.');
        }

        if ($operatorActionRequired !== ($resolution === DeliveryReconciliationResolution::OperatorResolutionRequired)) {
            throw new InvalidArgumentException('Operator action flag must match operator-required resolution.');
        }
    }

    public function terminal(): bool
    {
        return $this->resolution !== DeliveryReconciliationResolution::Pending;
    }
}
