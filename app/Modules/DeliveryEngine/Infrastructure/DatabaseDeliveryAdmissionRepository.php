<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryAdmissionRepository;
use App\Modules\DeliveryEngine\Domain\DeliveryAdmissionResult;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class DatabaseDeliveryAdmissionRepository implements DeliveryAdmissionRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function admit(DeliveryOperation $operation, string $providerOperation): DeliveryAdmissionResult
    {
        $connection = $this->database->connection();
        $now = $this->clock->now();
        $row = $connection->table('delivery_operations')
            ->where('id', $operation->id)
            ->where('workspace_id', $operation->workspaceId)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof stdClass) {
            return new DeliveryAdmissionResult(
                operation: $operation,
                admitted: false,
                changed: false,
                providerId: null,
                providerConnectionId: null,
                backpressureReason: 'operation_unavailable',
            );
        }

        $locked = $this->toOperation($row);
        if ($locked->state === DeliveryOperationState::Leased) {
            return new DeliveryAdmissionResult(
                operation: $locked,
                admitted: true,
                changed: false,
                providerId: $locked->providerId,
                providerConnectionId: $locked->providerConnectionId,
                backpressureReason: null,
            );
        }

        if ($locked->scheduledNotBeforeAt > $now) {
            return new DeliveryAdmissionResult(
                operation: $locked,
                admitted: false,
                changed: false,
                providerId: null,
                providerConnectionId: null,
                backpressureReason: 'scheduled_not_before',
            );
        }

        $candidates = $connection->table('provider_connections as connections')
            ->join('provider_capabilities as capabilities', function ($join): void {
                $join->on('capabilities.workspace_id', '=', 'connections.workspace_id')
                    ->on('capabilities.provider_id', '=', 'connections.provider_id')
                    ->on('capabilities.connection_id', '=', 'connections.id');
            })
            ->where('connections.workspace_id', $locked->workspaceId)
            ->where('connections.readiness_status', 'ready')
            ->where('capabilities.operation', $providerOperation)
            ->where('capabilities.support_status', 'supported')
            ->where(function ($query) use ($now): void {
                $query->whereNull('connections.fresh_until')
                    ->orWhere('connections.fresh_until', '>=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('capabilities.fresh_until')
                    ->orWhere('capabilities.fresh_until', '>=', $now);
            })
            ->select([
                'connections.id as connection_id',
                'connections.provider_id',
            ])
            ->orderBy('connections.id')
            ->get();

        if ($candidates->isEmpty()) {
            return $this->backpressure($locked, 'provider_connection_unavailable', $now);
        }

        $lastReason = 'quota_evidence_missing';
        foreach ($candidates as $candidate) {
            if (! $candidate instanceof stdClass) {
                continue;
            }

            $providerId = (string) $candidate->provider_id;
            $connectionId = (string) $candidate->connection_id;
            $quotas = $connection->table('provider_quotas')
                ->where('workspace_id', $locked->workspaceId)
                ->where('provider_id', $providerId)
                ->where('connection_id', $connectionId)
                ->where('operation', $providerOperation)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('fresh_until')
                        ->orWhere('fresh_until', '>=', $now);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($quotas->isEmpty()) {
                continue;
            }

            $quotaRows = [];
            $quotaRequiredUnits = [];
            $blocked = false;
            foreach ($quotas as $quota) {
                if (! $quota instanceof stdClass) {
                    continue;
                }

                if ($quota->resets_at !== null && new DateTimeImmutable((string) $quota->resets_at) <= $now) {
                    $lastReason = 'quota_evidence_stale';
                    $blocked = true;
                    break;
                }

                $available = $this->availableUnits($quota);
                if ($available === null) {
                    $lastReason = 'quota_evidence_incomplete';
                    $blocked = true;
                    break;
                }

                $requiredUnits = $this->requiredUnits($quota);
                if ($requiredUnits === null) {
                    $lastReason = 'quota_operation_cost_unknown';
                    $blocked = true;
                    break;
                }

                $consumed = (float) $connection->table('delivery_operation_quota_consumptions')
                    ->where('workspace_id', $locked->workspaceId)
                    ->where('quota_id', (string) $quota->id)
                    ->sum('units');

                if (($available - $consumed) < $requiredUnits) {
                    $lastReason = 'quota_exhausted';
                    $blocked = true;
                    break;
                }

                $quotaRows[] = $quota;
                $quotaRequiredUnits[(string) $quota->id] = $requiredUnits;
            }

            if ($blocked || $quotaRows === []) {
                continue;
            }

            foreach ($quotaRows as $quota) {
                $connection->table('delivery_operation_quota_consumptions')->insertOrIgnore([
                    'workspace_id' => $locked->workspaceId,
                    'operation_id' => $locked->id,
                    'provider_id' => $providerId,
                    'provider_connection_id' => $connectionId,
                    'quota_id' => (string) $quota->id,
                    'units' => $quotaRequiredUnits[(string) $quota->id],
                    'created_at' => $now,
                ]);
            }

            $connection->table('delivery_operations')
                ->where('id', $locked->id)
                ->where('workspace_id', $locked->workspaceId)
                ->update([
                    'provider_id' => $providerId,
                    'provider_connection_id' => $connectionId,
                    'state' => DeliveryOperationState::Leased->value,
                    'backpressure_reason' => null,
                    'backpressured_at' => null,
                    'version' => $locked->version + 1,
                    'updated_at' => $now,
                ]);

            $admitted = $this->readOperation($locked->workspaceId, $locked->id) ?? $locked;

            return new DeliveryAdmissionResult(
                operation: $admitted,
                admitted: true,
                changed: true,
                providerId: $providerId,
                providerConnectionId: $connectionId,
                backpressureReason: null,
            );
        }

        return $this->backpressure($locked, $lastReason, $now);
    }

    private function availableUnits(stdClass $quota): ?float
    {
        if ($quota->remaining_value !== null) {
            return max(0.0, (float) $quota->remaining_value);
        }

        if ($quota->limit_value !== null && $quota->used_value !== null) {
            return max(0.0, (float) $quota->limit_value - (float) $quota->used_value);
        }

        return null;
    }

    private function requiredUnits(stdClass $quota): ?float
    {
        $unit = (string) $quota->unit;
        if (in_array($unit, ['request', 'recipient'], true)) {
            return 1.0;
        }

        $metadata = json_decode((string) $quota->metadata, true);
        if (! is_array($metadata)) {
            return null;
        }

        $cost = $metadata['operation_cost_units'] ?? null;
        if (! is_int($cost) && ! is_float($cost) && ! (is_string($cost) && is_numeric($cost))) {
            return null;
        }

        $requiredUnits = (float) $cost;

        return is_finite($requiredUnits) && $requiredUnits > 0 ? $requiredUnits : null;
    }

    private function backpressure(
        DeliveryOperation $operation,
        string $reason,
        DateTimeImmutable $now,
    ): DeliveryAdmissionResult {
        if (
            $operation->state === DeliveryOperationState::Backpressured
            && $operation->backpressureReason === $reason
        ) {
            return new DeliveryAdmissionResult(
                operation: $operation,
                admitted: false,
                changed: false,
                providerId: null,
                providerConnectionId: null,
                backpressureReason: $reason,
            );
        }

        $backpressuredAt = $operation->backpressuredAt ?? $now;
        $this->database->connection()->table('delivery_operations')
            ->where('id', $operation->id)
            ->where('workspace_id', $operation->workspaceId)
            ->update([
                'provider_id' => null,
                'provider_connection_id' => null,
                'state' => DeliveryOperationState::Backpressured->value,
                'backpressure_reason' => $reason,
                'backpressured_at' => $backpressuredAt,
                'version' => $operation->version + 1,
                'updated_at' => $now,
            ]);

        $updated = $this->readOperation($operation->workspaceId, $operation->id) ?? $operation;

        return new DeliveryAdmissionResult(
            operation: $updated,
            admitted: false,
            changed: true,
            providerId: null,
            providerConnectionId: null,
            backpressureReason: $reason,
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
            backpressuredAt: $row->backpressured_at === null ? null : new DateTimeImmutable((string) $row->backpressured_at),
            version: (int) $row->version,
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: new DateTimeImmutable((string) $row->updated_at),
        );
    }
}
