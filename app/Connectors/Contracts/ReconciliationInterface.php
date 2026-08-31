<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

interface ReconciliationInterface
{
    /**
     * Reconcile external async operation using provider operation id or webhook payload.
     * Should return a normalized terminal state string (success, failed, pending) and metadata.
     *
     * @param string $operationId
     * @return array{state: string, metadata: array}
     */
    public function reconcile(string $operationId): array;
}
