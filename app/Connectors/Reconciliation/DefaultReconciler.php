<?php

declare(strict_types=1);

namespace App\Connectors\Reconciliation;

use App\Connectors\Contracts\ReconciliationInterface;

/**
 * Default reconciler scaffold. Real providers should implement provider-specific pollers.
 */
class DefaultReconciler implements ReconciliationInterface
{
    public function reconcile(string $operationId): array
    {
        // Not implemented: return pending with metadata to indicate the need for provider implementation
        return [
            'state' => 'pending',
            'metadata' => [
                'operation_id' => $operationId,
                'note' => 'DefaultReconciler does not contact providers. Implement provider-specific reconciler.',
            ],
        ];
    }
}
