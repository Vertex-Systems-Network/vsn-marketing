<?php

namespace App\Modules\DeliveryEngine\Infrastructure;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\DeliveryEngine\Domain\Contracts\DeliveryRecoveryRepository;
use App\Modules\DeliveryEngine\Domain\DeliveryAttemptOutcomeClass;
use App\Modules\DeliveryEngine\Domain\DeliveryChannel;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerKey;
use App\Modules\DeliveryEngine\Domain\DeliveryCircuitBreakerState;
use App\Modules\DeliveryEngine\Domain\DeliveryDeadLetterDecision;
use App\Modules\DeliveryEngine\Domain\DeliveryDeadLetterReason;
use App\Modules\DeliveryEngine\Domain\DeliveryFailureObservation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperation;
use App\Modules\DeliveryEngine\Domain\DeliveryOperationState;
use App\Modules\DeliveryEngine\Domain\DeliveryPriorityClass;
use App\Modules\DeliveryEngine\Domain\DeliveryReconciliationResolution;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryAction;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoveryResult;
use App\Modules\DeliveryEngine\Domain\DeliveryRecoverySnapshot;
use App\Modules\DeliveryEngine\Domain\DeliveryRetryDecision;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final readonly class DatabaseDeliveryRecoveryRepository implements DeliveryRecoveryRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function lockSnapshot(
        string $workspaceId,
        string $operationId,
    ): ?DeliveryRecoverySnapshot {
        $connection = $this->database->connection();
        $row = $connection->table('delivery_operations')
            ->where('workspace_id', $workspaceId)
            ->where('id', $operationId)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $operation = $this->toOperation($row);
        if ($operation->providerId === null || $operation->providerConnectionId === null) {
            throw new RuntimeException('Delivery operation is not route-bound for recovery.');
        }

        $operationClass = $operation->channel->providerOperation();
        $breakerKey = new DeliveryCircuitBreakerKey(
            workspaceId: $operation->workspaceId,
            providerConnectionId: $operation->providerConnectionId,
            operationClass: $operationClass,
        );
        $now = $this->clock->now();

        $connection->table('delivery_circuit_breakers')->insertOrIgnore([
            'id' => $breakerKey->fingerprint(),
            'workspace_id' => $operation->workspaceId,
            'provider_id' => $operation->providerId,
            'provider_connection_id' => $operation->providerConnectionId,
            'operation_class' => $operationClass,
            'state' => DeliveryCircuitBreakerState::Closed->value,
            'consecutive_failures' => 0,
            'next_probe_at' => null,
            'probe_in_flight' => false,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $breaker = $connection->table('delivery_circuit_breakers')
            ->where('id', $breakerKey->fingerprint())
            ->where('workspace_id', $operation->workspaceId)
            ->where('provider_connection_id', $operation->providerConnectionId)
            ->where('operation_class', $operationClass)
            ->lockForUpdate()
            ->first();

        if (! $breaker instanceof stdClass) {
            throw new RuntimeException('Delivery circuit breaker could not be locked.');
        }

        if ((string) $breaker->provider_id !== $operation->providerId) {
            throw new RuntimeException('Delivery circuit breaker route evidence is inconsistent.');
        }

        return new DeliveryRecoverySnapshot(
            operation: $operation,
            operationClass: $operationClass,
            breakerState: DeliveryCircuitBreakerState::from((string) $breaker->state),
            breakerConsecutiveFailures: (int) $breaker->consecutive_failures,
            breakerNextProbeAt: $breaker->next_probe_at === null
                ? null
                : new DateTimeImmutable((string) $breaker->next_probe_at),
            breakerProbeInFlight: (bool) $breaker->probe_in_flight,
            breakerVersion: (int) $breaker->version,
        );
    }

    public function findRecordedAttempt(
        DeliveryRecoverySnapshot $snapshot,
        DeliveryFailureObservation $observation,
    ): ?DeliveryRecoveryResult {
        $row = $this->database->connection()->table('delivery_attempts')
            ->where('workspace_id', $snapshot->operation->workspaceId)
            ->where('operation_id', $snapshot->operation->id)
            ->where('attempt_number', $observation->attemptNumber)
            ->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $this->assertObservationMatches($row, $snapshot, $observation);

        return $this->resultFromAttempt($snapshot->operation, $row, changed: false);
    }

    public function recordAttemptOutcome(
        string $attemptId,
        DeliveryRecoverySnapshot $snapshot,
        DeliveryFailureObservation $observation,
        DeliveryRetryDecision $retryDecision,
        DeliveryCircuitBreakerDecision $breakerDecision,
        int $breakerConsecutiveFailuresAfterOutcome,
        DeliveryDeadLetterDecision $deadLetterDecision,
        DateTimeImmutable $observedAt,
        ?DateTimeImmutable $nextAttemptAt,
        int $maxReconciliationProbeAttempts,
    ): DeliveryRecoveryResult {
        if (trim($attemptId) === '') {
            throw new InvalidArgumentException('Delivery attempt id must not be empty.');
        }

        if ($breakerConsecutiveFailuresAfterOutcome < 0) {
            throw new InvalidArgumentException('Delivery breaker failure streak must not be negative.');
        }

        if ($maxReconciliationProbeAttempts < 1) {
            throw new InvalidArgumentException('Reconciliation maximum probes must be at least one.');
        }

        $existing = $this->findRecordedAttempt($snapshot, $observation);
        if ($existing !== null) {
            return $existing;
        }

        if ($snapshot->operation->state !== DeliveryOperationState::Leased) {
            throw new RuntimeException('Only a leased delivery operation may record a new attempt outcome.');
        }

        if ($snapshot->operation->providerId === null || $snapshot->operation->providerConnectionId === null) {
            throw new RuntimeException('A leased delivery operation must retain its provider route.');
        }

        $connection = $this->database->connection();
        $lastAttemptNumber = (int) $connection->table('delivery_attempts')
            ->where('workspace_id', $snapshot->operation->workspaceId)
            ->where('operation_id', $snapshot->operation->id)
            ->max('attempt_number');
        $expectedAttemptNumber = $lastAttemptNumber + 1;

        if ($observation->attemptNumber !== $expectedAttemptNumber) {
            throw new RuntimeException('Delivery attempt numbering must be contiguous and monotonic.');
        }

        $this->assertDecisionMatchesObservation($observation, $retryDecision);

        if ($breakerDecision->resetFailureCount && $breakerConsecutiveFailuresAfterOutcome !== 0) {
            throw new RuntimeException('A breaker reset decision must persist a zero failure streak.');
        }

        if ($retryDecision->retryAllowed && $nextAttemptAt === null) {
            throw new RuntimeException('Retryable delivery outcomes require an explicit next-attempt timestamp.');
        }

        if (! $retryDecision->retryAllowed && $nextAttemptAt !== null) {
            throw new RuntimeException('Non-retry delivery outcomes cannot carry a next-attempt timestamp.');
        }

        $targetState = $this->targetState(
            retryDecision: $retryDecision,
            deadLetterDecision: $deadLetterDecision,
            observedAt: $observedAt,
            nextAttemptAt: $nextAttemptAt,
        );

        $connection->table('delivery_attempts')->insert([
            'id' => $attemptId,
            'workspace_id' => $snapshot->operation->workspaceId,
            'operation_id' => $snapshot->operation->id,
            'provider_id' => $snapshot->operation->providerId,
            'provider_connection_id' => $snapshot->operation->providerConnectionId,
            'attempt_number' => $observation->attemptNumber,
            'operation_class' => $snapshot->operationClass,
            'outcome_class' => $retryDecision->outcomeClass->value,
            'recovery_action' => $retryDecision->action->value,
            'retry_allowed' => $retryDecision->retryAllowed,
            'failure_reason' => $retryDecision->reason,
            'operation_state_after' => $targetState->value,
            'next_attempt_at' => $nextAttemptAt,
            'error_category' => $observation->errorCategory?->value,
            'http_status' => $observation->httpStatus,
            'minimum_delay_seconds' => $observation->minimumDelaySeconds,
            'reset_at' => $observation->resetAt,
            'provider_accepted' => $observation->providerAccepted,
            'acceptance_known_not_occurred' => $observation->acceptanceKnownNotOccurred,
            'request_may_have_reached_provider' => $observation->requestMayHaveReachedProvider,
            'max_attempts' => $observation->maxAttempts,
            'observed_at' => $observedAt,
            'created_at' => $observedAt,
        ]);

        $breakerKey = new DeliveryCircuitBreakerKey(
            workspaceId: $snapshot->operation->workspaceId,
            providerConnectionId: $snapshot->operation->providerConnectionId,
            operationClass: $snapshot->operationClass,
        );
        $breakerUpdated = $connection->table('delivery_circuit_breakers')
            ->where('id', $breakerKey->fingerprint())
            ->where('workspace_id', $snapshot->operation->workspaceId)
            ->where('version', $snapshot->breakerVersion)
            ->update([
                'state' => $breakerDecision->state->value,
                'consecutive_failures' => $breakerConsecutiveFailuresAfterOutcome,
                'next_probe_at' => $breakerDecision->nextProbeAt,
                'probe_in_flight' => false,
                'version' => $snapshot->breakerVersion + 1,
                'updated_at' => $observedAt,
            ]);

        if ($breakerUpdated !== 1) {
            throw new RuntimeException('Delivery circuit breaker changed during locked recovery.');
        }

        if ($retryDecision->action === DeliveryRecoveryAction::Reconcile) {
            $connection->table('delivery_reconciliations')->insert([
                'workspace_id' => $snapshot->operation->workspaceId,
                'operation_id' => $snapshot->operation->id,
                'attempt_id' => $attemptId,
                'resolution' => DeliveryReconciliationResolution::Pending->value,
                'provider_accepted' => false,
                'acceptance_known_not_occurred' => false,
                'retry_safe' => false,
                'probe_attempt_number' => 0,
                'max_probe_attempts' => $maxReconciliationProbeAttempts,
                'operator_action_required' => false,
                'reason' => $retryDecision->reason,
                'evidence_observed_at' => null,
                'resolved_at' => null,
                'created_at' => $observedAt,
                'updated_at' => $observedAt,
            ]);
        }

        if ($deadLetterDecision->eligible) {
            if ($deadLetterDecision->reason === null) {
                throw new RuntimeException('Eligible dead-letter decisions require a terminal reason.');
            }

            $connection->table('delivery_dead_letters')->insert([
                'workspace_id' => $snapshot->operation->workspaceId,
                'operation_id' => $snapshot->operation->id,
                'attempt_id' => $attemptId,
                'reason' => $deadLetterDecision->reason->value,
                'audit_reason' => $deadLetterDecision->auditReason,
                'created_at' => $observedAt,
            ]);
        }

        $operationUpdates = [
            'state' => $targetState->value,
            'backpressure_reason' => null,
            'backpressured_at' => null,
            'version' => $snapshot->operation->version + 1,
            'updated_at' => $observedAt,
        ];
        if ($nextAttemptAt !== null) {
            $operationUpdates['scheduled_not_before_at'] = $nextAttemptAt;
        }

        $operationUpdated = $connection->table('delivery_operations')
            ->where('workspace_id', $snapshot->operation->workspaceId)
            ->where('id', $snapshot->operation->id)
            ->where('state', DeliveryOperationState::Leased->value)
            ->where('version', $snapshot->operation->version)
            ->update($operationUpdates);

        if ($operationUpdated !== 1) {
            throw new RuntimeException('Delivery operation changed during locked recovery.');
        }

        $updatedOperation = $this->readOperation(
            $snapshot->operation->workspaceId,
            $snapshot->operation->id,
        );
        if ($updatedOperation === null) {
            throw new RuntimeException('Recovered delivery operation could not be reloaded.');
        }

        return new DeliveryRecoveryResult(
            operation: $updatedOperation,
            attemptId: $attemptId,
            attemptNumber: $observation->attemptNumber,
            outcomeClass: $retryDecision->outcomeClass,
            action: $retryDecision->action,
            changed: true,
            nextAttemptAt: $nextAttemptAt,
            deadLetterReason: $deadLetterDecision->reason,
            reconciliationResolution: $retryDecision->action === DeliveryRecoveryAction::Reconcile
                ? DeliveryReconciliationResolution::Pending
                : null,
        );
    }

    private function targetState(
        DeliveryRetryDecision $retryDecision,
        DeliveryDeadLetterDecision $deadLetterDecision,
        DateTimeImmutable $observedAt,
        ?DateTimeImmutable $nextAttemptAt,
    ): DeliveryOperationState {
        if ($deadLetterDecision->eligible) {
            return DeliveryOperationState::DeadLettered;
        }

        return match ($retryDecision->action) {
            DeliveryRecoveryAction::MarkAccepted => DeliveryOperationState::Accepted,
            DeliveryRecoveryAction::Reconcile => DeliveryOperationState::Reconciling,
            DeliveryRecoveryAction::HoldConnection => DeliveryOperationState::Held,
            DeliveryRecoveryAction::RetryWait,
            DeliveryRecoveryAction::RetrySameRoute => $retryDecision->retryAllowed && $nextAttemptAt !== null
                ? ($nextAttemptAt > $observedAt ? DeliveryOperationState::Scheduled : DeliveryOperationState::Ready)
                : throw new RuntimeException('Retry action is missing retry permission or timing.'),
            DeliveryRecoveryAction::FailOperation,
            DeliveryRecoveryAction::StopRetrying => throw new RuntimeException(
                'Terminal recovery action is missing dead-letter evidence.',
            ),
        };
    }

    private function assertDecisionMatchesObservation(
        DeliveryFailureObservation $observation,
        DeliveryRetryDecision $retryDecision,
    ): void {
        if (
            $observation->providerAccepted
            !== ($retryDecision->outcomeClass === DeliveryAttemptOutcomeClass::ProviderAccepted)
        ) {
            throw new RuntimeException('Provider acceptance evidence conflicts with the retry classification.');
        }

        if (
            $retryDecision->action === DeliveryRecoveryAction::Reconcile
            && $retryDecision->outcomeClass !== DeliveryAttemptOutcomeClass::AmbiguousTransport
        ) {
            throw new RuntimeException('Only ambiguous transport outcomes may enter reconciliation.');
        }
    }

    private function assertObservationMatches(
        stdClass $row,
        DeliveryRecoverySnapshot $snapshot,
        DeliveryFailureObservation $observation,
    ): void {
        $same = (string) $row->operation_class === $snapshot->operationClass
            && ($row->error_category === null ? null : (string) $row->error_category) === $observation->errorCategory?->value
            && ($row->http_status === null ? null : (int) $row->http_status) === $observation->httpStatus
            && ($row->minimum_delay_seconds === null ? null : (int) $row->minimum_delay_seconds) === $observation->minimumDelaySeconds
            && $this->sameInstant($row->reset_at, $observation->resetAt)
            && (bool) $row->provider_accepted === $observation->providerAccepted
            && (bool) $row->acceptance_known_not_occurred === $observation->acceptanceKnownNotOccurred
            && (bool) $row->request_may_have_reached_provider === $observation->requestMayHaveReachedProvider
            && (int) $row->max_attempts === $observation->maxAttempts;

        if (! $same) {
            throw new RuntimeException('Conflicting evidence was supplied for an existing delivery attempt number.');
        }
    }

    private function sameInstant(mixed $stored, ?DateTimeImmutable $expected): bool
    {
        if ($stored === null || $expected === null) {
            return $stored === null && $expected === null;
        }

        return (new DateTimeImmutable((string) $stored))->getTimestamp() === $expected->getTimestamp();
    }

    private function resultFromAttempt(
        DeliveryOperation $operation,
        stdClass $attempt,
        bool $changed,
    ): DeliveryRecoveryResult {
        $connection = $this->database->connection();
        $deadLetter = $connection->table('delivery_dead_letters')
            ->where('workspace_id', $operation->workspaceId)
            ->where('attempt_id', (string) $attempt->id)
            ->first();
        $reconciliation = $connection->table('delivery_reconciliations')
            ->where('workspace_id', $operation->workspaceId)
            ->where('attempt_id', (string) $attempt->id)
            ->first();

        return new DeliveryRecoveryResult(
            operation: $operation,
            attemptId: (string) $attempt->id,
            attemptNumber: (int) $attempt->attempt_number,
            outcomeClass: DeliveryAttemptOutcomeClass::from((string) $attempt->outcome_class),
            action: DeliveryRecoveryAction::from((string) $attempt->recovery_action),
            changed: $changed,
            nextAttemptAt: $attempt->next_attempt_at === null
                ? null
                : new DateTimeImmutable((string) $attempt->next_attempt_at),
            deadLetterReason: $deadLetter instanceof stdClass
                ? DeliveryDeadLetterReason::from((string) $deadLetter->reason)
                : null,
            reconciliationResolution: $reconciliation instanceof stdClass
                ? DeliveryReconciliationResolution::from((string) $reconciliation->resolution)
                : null,
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
