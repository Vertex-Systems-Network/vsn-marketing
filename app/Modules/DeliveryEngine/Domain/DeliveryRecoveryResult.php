<?php

namespace App\Modules\DeliveryEngine\Domain;

use DateTimeImmutable;

final readonly class DeliveryRecoveryResult
{
    public function __construct(
        public DeliveryOperation $operation,
        public string $attemptId,
        public int $attemptNumber,
        public DeliveryAttemptOutcomeClass $outcomeClass,
        public DeliveryRecoveryAction $action,
        public bool $changed,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?DeliveryDeadLetterReason $deadLetterReason = null,
        public ?DeliveryReconciliationResolution $reconciliationResolution = null,
    ) {}
}
