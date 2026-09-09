<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryDeadLetterDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryResult;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoverySnapshot;
use App\Modules\DeliveryEngine\Domain\DeliveryRetryDecision;
use DateTimeImmutable;

interface DeliveryRecoveryRepository
{
    public function lockSnapshot(
        string $workspaceId,
        string $operationId,
        string $operationClass,
    ): ?DeliveryRecoverySnapshot;

    public function recordAttemptOutcome(
        string $attemptId,
        DeliveryRecoverySnapshot $snapshot,
        DeliveryFailureObservation $observation,
        DeliveryRetryDecision $retryDecision,
        DeliveryCircuitBreakerDecision $breakerDecision,
        DeliveryDeadLetterDecision $deadLetterDecision,
        DateTimeImmutable $observedAt,
        ?DateTimeImmutable $nextAttemptAt,
    ): DeliveryRecoveryResult;
}
