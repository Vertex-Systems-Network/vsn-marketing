<?php

namespace App\Modules\DeliveryEngine\Domain\Contracts;

use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationEvidence;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResult;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationSnapshot;
use DateTimeImmutable;

interface DeliveryReconciliationRepository
{
    public function lockSnapshot(
        string $workspaceId,
        string $operationId,
        string $attemptId,
    ): ?DeliveryReconciliationSnapshot;

    public function resolve(
        DeliveryReconciliationSnapshot $snapshot,
        DeliveryReconciliationEvidence $evidence,
        DeliveryReconciliationDecision $decision,
        DateTimeImmutable $observedAt,
    ): DeliveryReconciliationResult;

    public function prepareFailover(
        string $workspaceId,
        string $operationId,
        string $attemptId,
        string $alternateProviderId,
        string $alternateProviderConnectionId,
        DateTimeImmutable $preparedAt,
    ): ?DeliveryOperation;
}
