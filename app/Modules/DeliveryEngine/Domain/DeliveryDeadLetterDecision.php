<?php

namespace App\Modules\DeliveryEngine\Domain;

use InvalidArgumentException;

final readonly class DeliveryDeadLetterDecision
{
    public function __construct(
        public bool $eligible,
        public ?DeliveryDeadLetterReason $reason,
        public bool $reconciliationRequired,
        public string $auditReason,
    ) {
        if (trim($auditReason) === '') {
            throw new InvalidArgumentException('Dead-letter audit reason must not be empty.');
        }

        if ($eligible !== ($reason !== null)) {
            throw new InvalidArgumentException('Dead-letter eligibility and terminal reason must agree.');
        }

        if ($eligible && $reconciliationRequired) {
            throw new InvalidArgumentException('Ambiguous reconciliation evidence cannot be dead-letter eligible.');
        }
    }
}
