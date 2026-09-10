<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryReconciliationRepository;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationEvidence;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResolution;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResult;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationSnapshot;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class DatabaseDeliveryReconciliationRepository implements DeliveryReconciliationRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function lockSnapshot(
        string $workspaceId,
        string $operationId,
        string $attemptId,
    ): ?DeliveryReconciliationSnapshot {
        $connection = $this->database->connection();
        $operationRow = $connection->table('delivery_operations')
            ->where('workspace_id', $workspaceId)
            ->where('id', $operationId)
            ->lockForUpdate()
            ->first();

        if (! $operationRow instanceof stdClass) {
            return null;
        }

        $reconciliation = $connection->table('delivery_reconciliations')
            ->where('workspace_id', $workspaceId)
            ->where('operation_id', $operationId)
            ->where('attempt_id', $attemptId)
            ->lockForUpdate()
            ->first();

        if (! $reconciliation instanceof stdClass) {
            return null;
        }

        return new DeliveryReconciliationSnapshot(
            operation: $this->toOperation($operationRow),
            attemptId: (string) $reconciliation->attempt_id,
            resolution: DeliveryReconciliationResolution::from((string) $reconciliation->resolution),
            providerAccepted: (bool) $reconciliation->provider_accepted,
            acceptanceKnownNotOccurred: (bool) $reconciliation->acceptance_known_not_occurred,
            retrySafe: (bool) $reconciliation->retry_safe,
            probeAttemptNumber: (int) $reconciliation->probe_attempt_number,
            maxProbeAttempts: (int) $reconciliation->max_probe_attempts,
            operatorActionRequired: (bool) $reconciliation->operator_action_required,
            reason: (string) $reconciliation->reason,
        );
    }

    public function resolve(
        DeliveryReconciliationSnapshot $snapshot,
        DeliveryReconciliationEvidence $evidence,
        DateTimeImmutable $observedAt,
    ): DeliveryReconciliationResult {
        if ($evidence->probeAttemptNumber === $snapshot->probeAttemptNumber) {
            $this->assertDuplicateEvidenceMatches($snapshot, $evidence);

            return $this->resultFromSnapshot($snapshot, changed: false);
        }

        if ($snapshot->resolution !== DeliveryReconciliationResolution::Pending) {
            throw new RuntimeException('Resolved delivery reconciliation cannot accept a new provider probe.');
        }

        if ($snapshot->operation->state !== DeliveryOperationState::Reconciling) {
            throw new RuntimeException('Pending delivery reconciliation requires a reconciling operation.');
        }

        if ($evidence->probeAttemptNumber !== $snapshot->probeAttemptNumber + 1) {
            throw new RuntimeException('Delivery reconciliation probe numbering must be contiguous and monotonic.');
        }

        if ($evidence->probeAttemptNumber > $snapshot->maxProbeAttempts) {
            throw new RuntimeException('Delivery reconciliation probe budget has been exhausted.');
        }

        $resolution = $this->resolutionFor($snapshot, $evidence);
        $operatorActionRequired = $resolution === DeliveryReconciliationResolution::OperatorResolutionRequired;
        $connection = $this->database->connection();
        $reconciliationUpdated = $connection->table('delivery_reconciliations')
            ->where('workspace_id', $snapshot->operation->workspaceId)
            ->where('operation_id', $snapshot->operation->id)
            ->where('attempt_id', $snapshot->attemptId)
            ->where('resolution', DeliveryReconciliationResolution::Pending->value)
            ->where('probe_attempt_number', $snapshot->probeAttemptNumber)
            ->update([
                'resolution' => $resolution->value,
                'provider_accepted' => $evidence->providerAccepted,
                'acceptance_known_not_occurred' => $evidence->acceptanceKnownNotOccurred,
                'retry_safe' => $evidence->retrySafe,
                'probe_attempt_number' => $evidence->probeAttemptNumber,
                'operator_action_required' => $operatorActionRequired,
                'reason' => $evidence->reason,
                'evidence_observed_at' => $observedAt,
                'resolved_at' => $resolution === DeliveryReconciliationResolution::Pending ? null : $observedAt,
                'updated_at' => $observedAt,
            ]);

        if ($reconciliationUpdated !== 1) {
            throw new RuntimeException('Delivery reconciliation changed during locked resolution.');
        }

        if ($resolution !== DeliveryReconciliationResolution::Pending) {
            $targetState = $resolution === DeliveryReconciliationResolution::Accepted
                ? DeliveryOperationState::Accepted
                : DeliveryOperationState::Held;
            $operationUpdated = $connection->table('delivery_operations')
                ->where('workspace_id', $snapshot->operation->workspaceId)
                ->where('id', $snapshot->operation->id)
                ->where('state', DeliveryOperationState::Reconciling->value)
                ->where('version', $snapshot->operation->version)
                ->update([
                    'state' => $targetState->value,
                    'backpressure_reason' => null,
                    'backpressured_at' => null,
                    'version' => $snapshot->operation->version + 1,
                    'updated_at' => $observedAt,
                ]);

            if ($operationUpdated !== 1) {
                throw new RuntimeException('Delivery operation changed during reconciliation resolution.');
            }
        }

        $operation = $this->readOperation(
            $snapshot->operation->workspaceId,
            $snapshot->operation->id,
        );
        if ($operation === null) {
            throw new RuntimeException('Reconciled delivery operation could not be reloaded.');
        }

        return new DeliveryReconciliationResult(
            operation: $operation,
            attemptId: $snapshot->attemptId,
            resolution: $resolution,
            changed: true,
            retryAllowed: $resolution === DeliveryReconciliationResolution::NotAcceptedRetrySafe,
            operatorActionRequired: $operatorActionRequired,
            reason: $evidence->reason,
        );
    }

    public function prepareFailover(
        string $workspaceId,
        string $operationId,
        string $attemptId,
        string $alternateProviderId,
        string $alternateProviderConnectionId,
        DateTimeImmutable $preparedAt,
    ): ?DeliveryOperation {
        if (trim($alternateProviderId) === '' || trim($alternateProviderConnectionId) === '') {
            throw new InvalidArgumentException('Failover provider route identifiers must not be empty.');
        }

        $connection = $this->database->connection();
        $operationRow = $connection->table('delivery_operations')
            ->where('workspace_id', $workspaceId)
            ->where('id', $operationId)
            ->lockForUpdate()
            ->first();
        $reconciliation = $connection->table('delivery_reconciliations')
            ->where('workspace_id', $workspaceId)
            ->where('operation_id', $operationId)
            ->where('attempt_id', $attemptId)
            ->lockForUpdate()
            ->first();
        $attempt = $connection->table('delivery_attempts')
            ->where('workspace_id', $workspaceId)
            ->where('operation_id', $operationId)
            ->where('id', $attemptId)
            ->first();

        if (
            ! $operationRow instanceof stdClass
            || ! $reconciliation instanceof stdClass
            || ! $attempt instanceof stdClass
        ) {
            return null;
        }

        $operation = $this->toOperation($operationRow);
        if (
            (string) $reconciliation->resolution !== DeliveryReconciliationResolution::NotAcceptedRetrySafe->value
            || ! (bool) $reconciliation->acceptance_known_not_occurred
            || ! (bool) $reconciliation->retry_safe
            || (bool) $reconciliation->provider_accepted
            || (bool) $reconciliation->operator_action_required
        ) {
            throw new RuntimeException('Delivery failover requires retry-safe provider non-acceptance evidence.');
        }

        if ($operation->state !== DeliveryOperationState::Held) {
            throw new RuntimeException('Delivery failover requires an explicitly held reconciled operation.');
        }

        if (
            $operation->providerId !== (string) $attempt->provider_id
            || $operation->providerConnectionId !== (string) $attempt->provider_connection_id
        ) {
            throw new RuntimeException('Delivery failover source route no longer matches the reconciled attempt.');
        }

        if ($alternateProviderConnectionId === (string) $attempt->provider_connection_id) {
            throw new RuntimeException('Delivery failover must select a different provider connection.');
        }

        $alternateConnection = $connection->table('provider_connections')
            ->where('workspace_id', $workspaceId)
            ->where('provider_id', $alternateProviderId)
            ->where('id', $alternateProviderConnectionId)
            ->where('readiness_status', 'ready')
            ->where(function ($query) use ($preparedAt): void {
                $query->whereNull('fresh_until')
                    ->orWhere('fresh_until', '>=', $preparedAt);
            })
            ->lockForUpdate()
            ->first();

        if (! $alternateConnection instanceof stdClass) {
            throw new RuntimeException('Alternate delivery provider connection is not ready or tenant-compatible.');
        }

        $capability = $connection->table('provider_capabilities')
            ->where('workspace_id', $workspaceId)
            ->where('provider_id', $alternateProviderId)
            ->where('connection_id', $alternateProviderConnectionId)
            ->where('operation', $operation->channel->providerOperation())
            ->where('support_status', 'supported')
            ->where(function ($query) use ($preparedAt): void {
                $query->whereNull('fresh_until')
                    ->orWhere('fresh_until', '>=', $preparedAt);
            })
            ->first();

        if (! $capability instanceof stdClass) {
            throw new RuntimeException('Alternate delivery provider route lacks compatible capability evidence.');
        }

        $updated = $connection->table('delivery_operations')
            ->where('workspace_id', $workspaceId)
            ->where('id', $operationId)
            ->where('state', DeliveryOperationState::Held->value)
            ->where('version', $operation->version)
            ->update([
                'provider_id' => $alternateProviderId,
                'provider_connection_id' => $alternateProviderConnectionId,
                'state' => DeliveryOperationState::Ready->value,
                'scheduled_not_before_at' => $preparedAt,
                'backpressure_reason' => null,
                'backpressured_at' => null,
                'version' => $operation->version + 1,
                'updated_at' => $preparedAt,
            ]);

        if ($updated !== 1) {
            throw new RuntimeException('Delivery operation changed during failover preparation.');
        }

        return $this->readOperation($workspaceId, $operationId)
            ?? throw new RuntimeException('Failover delivery operation could not be reloaded.');
    }

    private function resolutionFor(
        DeliveryReconciliationSnapshot $snapshot,
        DeliveryReconciliationEvidence $evidence,
    ): DeliveryReconciliationResolution {
        if ($evidence->providerAccepted) {
            return DeliveryReconciliationResolution::Accepted;
        }

        if ($evidence->acceptanceKnownNotOccurred && $evidence->retrySafe) {
            return DeliveryReconciliationResolution::NotAcceptedRetrySafe;
        }

        if ($evidence->acceptanceKnownNotOccurred || $evidence->probeAttemptNumber >= $snapshot->maxProbeAttempts) {
            return DeliveryReconciliationResolution::OperatorResolutionRequired;
        }

        return DeliveryReconciliationResolution::Pending;
    }

    private function assertDuplicateEvidenceMatches(
        DeliveryReconciliationSnapshot $snapshot,
        DeliveryReconciliationEvidence $evidence,
    ): void {
        if (
            $snapshot->providerAccepted !== $evidence->providerAccepted
            || $snapshot->acceptanceKnownNotOccurred !== $evidence->acceptanceKnownNotOccurred
            || $snapshot->retrySafe !== $evidence->retrySafe
            || $snapshot->reason !== $evidence->reason
        ) {
            throw new RuntimeException('Conflicting evidence was supplied for an existing reconciliation probe.');
        }
    }

    private function resultFromSnapshot(
        DeliveryReconciliationSnapshot $snapshot,
        bool $changed,
    ): DeliveryReconciliationResult {
        return new DeliveryReconciliationResult(
            operation: $snapshot->operation,
            attemptId: $snapshot->attemptId,
            resolution: $snapshot->resolution,
            changed: $changed,
            retryAllowed: $snapshot->resolution === DeliveryReconciliationResolution::NotAcceptedRetrySafe,
            operatorActionRequired: $snapshot->operatorActionRequired,
            reason: $snapshot->reason,
        );
    }

    private function readOperation(string $workspaceId, string $operationId): ?DeliveryOperation
    {
        $row = $this->database->connection()->table('delivery_operations')
            ->where('workspace_id', $workspaceId)
            ->where('id', $operationId)
            ->first();

        return $row instanceof stdClass ? $this->toOperation($row) : null;
    }

    private function toOperation(stdClass $row): DeliveryOperation
    {
        return new DeliveryOperation(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            messageSnapshotId: (string) $row->message_snapshot_id,
            recipientSnapshotId: (string) $row->recipient_snapshot_id,
            providerId: $row->provider_id === null ? null : (string) $row->provider_id,
            providerConnectionId: $row->provider_connection_id === null ? null : (string) $row->provider_connection_id,
            channel: DeliveryChannel::from((string) $row->channel),
            idempotencyKey: (string) $row->idempotency_key,
            scheduledNotBeforeAt: new DateTimeImmutable((string) $row->scheduled_not_before_at),
            priorityClass: DeliveryPriorityClass::from((string) $row->priority_class),
            state: DeliveryOperationState::from((string) $row->state),
            queueName: (string) $row->queue_name,
            queuePartitionKey: (string) $row->queue_partition_key,
            backpressureReason: $row->backpressure_reason === null ? null : (string) $row->backpressure_reason,
            backpressuredAt: $row->backpressured_at === null
                ? null
                : new DateTimeImmutable((string) $row->backpressured_at),
            version: (int) $row->version,
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: new DateTimeImmutable((string) $row->updated_at),
        );
    }
}
