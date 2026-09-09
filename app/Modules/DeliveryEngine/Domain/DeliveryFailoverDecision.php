<?php

namespace App\Modules\DeliveryEngine\Domain;

use InvalidArgumentException;

final readonly class DeliveryFailoverDecision
{
    public function __construct(
        public bool $eligible,
        public bool $createNewAttempt,
        public bool $preserveLogicalOperationIdentity,
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Failover decision reason must not be empty.');
        }

        if ($eligible !== $createNewAttempt) {
            throw new InvalidArgumentException('Eligible failover must create exactly one new attempt.');
        }

        if ($eligible !== $preserveLogicalOperationIdentity) {
            throw new InvalidArgumentException('Eligible failover must preserve the logical operation identity.');
        }
    }
}
