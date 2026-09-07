<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class ExecutionSnapshots
{
    public function __construct(
        public MessageExecutionSnapshot $message,
        public RecipientExecutionSnapshot $recipient,
    ) {}
}
