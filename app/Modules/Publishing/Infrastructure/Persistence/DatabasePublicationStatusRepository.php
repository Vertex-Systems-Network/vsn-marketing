<?php

namespace App\Modules\Publishing\Infrastructure\Persistence;

use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Domain\Publication\PublicationStatusObservation;
use App\Modules\Publishing\Domain\Publication\PublicationStatusProjection;
use App\Modules\Publishing\Domain\Publication\PublicationStatusReconciliationResult;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class DatabasePublicationStatusRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function record(PublicationStatusObservation $observation): PublicationStatusReconciliationResult
    {
        return $this->database->connection()->transaction(function () use ($observation): PublicationStatusReconciliationResult {
            $this->assertAuthority($observation);
            $this->assertProviderOperationOwnership($observation);

            $existing = $this->database->connection()->table('publication_status_observations')
                ->where('workspace_id', $observation->workspaceId)
                ->where('idempotency_key', $observation->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof stdClass) {
                $stored = $this->hydrateObservation($existing);
                $this->assertReplay($stored, $observation);
                [$projection, $advanced] = $this->reconcileProjection($stored);

                return new PublicationStatusReconciliationResult($stored, $projection, $advanced);
            }

            $this->denyIfForeignObservationIdExists($observation->workspaceId, $observation->id);

            $inserted = $this->database->connection()->table('publication_status_observations')->insertOrIgnore([
                'id' => $observation->id,
                'workspace_id' => $observation->workspaceId,
                'publication_attempt_id' => $observation->publicationAttemptId,
                'provider_connection_id' => $observation->providerConnectionId,
                'capability_evidence_id' => $observation->capabilityEvidenceId,
                'provider_id' => $observation->providerId,
                'provider_operation_id' => $observation->providerOperationId,
                'normalized_status' => $observation->normalizedStatus->value,
                'provider_status' => $observation->providerStatus,
                'reconciliation_source' => $observation->source->value,
                'source_reference' => $observation->sourceReference,
                'provider_observed_at' => $observation->providerObservedAt,
                'received_at' => $observation->receivedAt,
                'evidence' => $this->encodeJson($observation->evidence),
                'idempotency_key' => $observation->idempotencyKey,
                'observation_hash' => $observation->observationHash,
            ]);

            $stored = $observation;
            if ($inserted !== 1) {
                $winner = $this->database->connection()->table('publication_status_observations')
                    ->where('workspace_id', $observation->workspaceId)
                    ->where(function ($query) use ($observation): void {
                        $query->where('id', $observation->id)
                            ->orWhere('idempotency_key', $observation->idempotencyKey);
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $winner instanceof stdClass) {
                    throw new InvalidArgumentException('Publication status observation conflicts with canonical reconciliation state.');
                }

                $stored = $this->hydrateObservation($winner);
                $this->assertReplay($stored, $observation);
            }

            [$projection, $advanced] = $this->reconcileProjection($stored);

            return new PublicationStatusReconciliationResult($stored, $projection, $advanced);
        });
    }

    /** @return list<PublicationStatusObservation> */
    public function history(string $workspaceId, string $publicationAttemptId): array
    {
        $this->assertAttemptAccess($workspaceId, $publicationAttemptId);

        return $this->database->connection()->table('publication_status_observations')
            ->where('workspace_id', $workspaceId)
            ->where('publication_attempt_id', $publicationAttemptId)
            ->orderBy('sequence')
            ->get()
            ->map(fn (stdClass $row): PublicationStatusObservation => $this->hydrateObservation($row))
            ->all();
    }

    public function findProjection(string $workspaceId, string $publicationAttemptId): ?PublicationStatusProjection
    {
        $this->assertAttemptAccess($workspaceId, $publicationAttemptId);

        $row = $this->database->connection()->table('publication_status_projections')
            ->where('workspace_id', $workspaceId)
            ->where('publication_attempt_id', $publicationAttemptId)
            ->first();

        return $row instanceof stdClass ? $this->hydrateProjection($row) : null;
    }

    private function assertAuthority(PublicationStatusObservation $observation): void
    {
        $attempt = $this->database->connection()->table('publication_attempts')
            ->where('workspace_id', $observation->workspaceId)
            ->where('id', $observation->publicationAttemptId)
            ->lockForUpdate()
            ->first();

        if (! $attempt instanceof stdClass) {
            if ($this->database->connection()->table('publication_attempts')
                ->where('id', $observation->publicationAttemptId)
                ->where('workspace_id', '<>', $observation->workspaceId)
                ->exists()) {
                throw new AuthorizationException('Publication status attempt access denied.');
            }

            throw new InvalidArgumentException('Publication status attempt does not exist in this workspace.');
        }

        if (
            (string) $attempt->provider_connection_id !== $observation->providerConnectionId
            || (string) $attempt->capability_evidence_id !== $observation->capabilityEvidenceId
            || (string) $attempt->provider_id !== $observation->providerId
        ) {
            throw new InvalidArgumentException('Publication status observation does not match immutable provider authority.');
        }
    }

    private function assertProviderOperationOwnership(PublicationStatusObservation $observation): void
    {
        $attemptProjection = $this->database->connection()->table('publication_status_projections')
            ->where('workspace_id', $observation->workspaceId)
            ->where('publication_attempt_id', $observation->publicationAttemptId)
            ->lockForUpdate()
            ->first();

        if (
            $attemptProjection instanceof stdClass
            && (string) $attemptProjection->provider_operation_id !== $observation->providerOperationId
        ) {
            throw new InvalidArgumentException('Publication status provider operation ID conflicts with canonical attempt reconciliation.');
        }

        $operationProjection = $this->database->connection()->table('publication_status_projections')
            ->where('workspace_id', $observation->workspaceId)
            ->where('provider_connection_id', $observation->providerConnectionId)
            ->where('provider_operation_id', $observation->providerOperationId)
            ->lockForUpdate()
            ->first();

        if (
            $operationProjection instanceof stdClass
            && (string) $operationProjection->publication_attempt_id !== $observation->publicationAttemptId
        ) {
            throw new InvalidArgumentException('Publication status provider operation ID is already bound to another attempt.');
        }
    }

    /** @return array{PublicationStatusProjection, bool} */
    private function reconcileProjection(PublicationStatusObservation $observation): array
    {
        $row = $this->database->connection()->table('publication_status_projections')
            ->where('workspace_id', $observation->workspaceId)
            ->where('publication_attempt_id', $observation->publicationAttemptId)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof stdClass) {
            $initial = PublicationStatusProjection::initial($observation);
            $inserted = $this->database->connection()->table('publication_status_projections')->insertOrIgnore(
                $this->projectionPayload($initial),
            );

            if ($inserted === 1) {
                return [$initial, true];
            }

            $row = $this->database->connection()->table('publication_status_projections')
                ->where('workspace_id', $observation->workspaceId)
                ->where('publication_attempt_id', $observation->publicationAttemptId)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof stdClass) {
                throw new InvalidArgumentException('Publication status projection could not be resolved after concurrent creation.');
            }
        }

        $current = $this->hydrateProjection($row);
        $next = $current->apply($observation);

        if ($next === $current) {
            return [$current, false];
        }

        $updated = $this->database->connection()->table('publication_status_projections')
            ->where('workspace_id', $current->workspaceId)
            ->where('publication_attempt_id', $current->publicationAttemptId)
            ->where('projection_version', $current->projectionVersion)
            ->update([
                'normalized_status' => $next->normalizedStatus->value,
                'provider_status' => $next->providerStatus,
                'provider_observed_at' => $next->providerObservedAt,
                'reconciliation_source' => $next->source->value,
                'source_reference' => $next->sourceReference,
                'current_observation_id' => $next->currentObservationId,
                'current_observation_hash' => $next->currentObservationHash,
                'projection_version' => $next->projectionVersion,
                'updated_at' => $next->updatedAt,
            ]);

        if ($updated !== 1) {
            throw new InvalidArgumentException('Publication status projection lost optimistic concurrency.');
        }

        return [$next, true];
    }

    /** @return array<string, mixed> */
    private function projectionPayload(PublicationStatusProjection $projection): array
    {
        return [
            'workspace_id' => $projection->workspaceId,
            'publication_attempt_id' => $projection->publicationAttemptId,
            'provider_connection_id' => $projection->providerConnectionId,
            'capability_evidence_id' => $projection->capabilityEvidenceId,
            'provider_id' => $projection->providerId,
            'provider_operation_id' => $projection->providerOperationId,
            'normalized_status' => $projection->normalizedStatus->value,
            'provider_status' => $projection->providerStatus,
            'provider_observed_at' => $projection->providerObservedAt,
            'reconciliation_source' => $projection->source->value,
            'source_reference' => $projection->sourceReference,
            'current_observation_id' => $projection->currentObservationId,
            'current_observation_hash' => $projection->currentObservationHash,
            'projection_version' => $projection->projectionVersion,
            'updated_at' => $projection->updatedAt,
        ];
    }

    private function assertReplay(PublicationStatusObservation $stored, PublicationStatusObservation $candidate): void
    {
        if (
            $stored->workspaceId !== $candidate->workspaceId
            || $stored->publicationAttemptId !== $candidate->publicationAttemptId
            || $stored->providerConnectionId !== $candidate->providerConnectionId
            || $stored->capabilityEvidenceId !== $candidate->capabilityEvidenceId
            || $stored->providerId !== $candidate->providerId
            || $stored->providerOperationId !== $candidate->providerOperationId
            || $stored->normalizedStatus !== $candidate->normalizedStatus
            || $stored->providerStatus !== $candidate->providerStatus
            || $stored->source !== $candidate->source
            || $stored->sourceReference !== $candidate->sourceReference
            || $stored->providerObservedAt != $candidate->providerObservedAt
            || $stored->receivedAt != $candidate->receivedAt
            || $stored->evidence !== $candidate->evidence
            || ! hash_equals($stored->idempotencyKey, $candidate->idempotencyKey)
            || ! hash_equals($stored->observationHash, $candidate->observationHash)
        ) {
            throw new InvalidArgumentException('Publication status observation replay conflicts with immutable provenance.');
        }
    }

    private function assertAttemptAccess(string $workspaceId, string $publicationAttemptId): void
    {
        if ($this->database->connection()->table('publication_attempts')
            ->where('workspace_id', $workspaceId)
            ->where('id', $publicationAttemptId)
            ->exists()) {
            return;
        }

        if ($this->database->connection()->table('publication_attempts')
            ->where('id', $publicationAttemptId)
            ->where('workspace_id', '<>', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Publication status attempt access denied.');
        }

        throw new InvalidArgumentException('Publication status attempt does not exist in this workspace.');
    }

    private function denyIfForeignObservationIdExists(string $workspaceId, string $observationId): void
    {
        if ($this->database->connection()->table('publication_status_observations')
            ->where('id', $observationId)
            ->where('workspace_id', '<>', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Publication status observation access denied.');
        }
    }

    private function hydrateObservation(stdClass $row): PublicationStatusObservation
    {
        return new PublicationStatusObservation(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            publicationAttemptId: (string) $row->publication_attempt_id,
            providerConnectionId: (string) $row->provider_connection_id,
            capabilityEvidenceId: (string) $row->capability_evidence_id,
            providerId: (string) $row->provider_id,
            providerOperationId: (string) $row->provider_operation_id,
            normalizedStatus: ProviderOperationStatus::from((string) $row->normalized_status),
            providerStatus: (string) $row->provider_status,
            source: ReconciliationSource::from((string) $row->reconciliation_source),
            sourceReference: (string) $row->source_reference,
            providerObservedAt: $this->utc((string) $row->provider_observed_at),
            receivedAt: $this->utc((string) $row->received_at),
            evidence: $this->decodeJson($row->evidence),
            idempotencyKey: (string) $row->idempotency_key,
            observationHash: (string) $row->observation_hash,
        );
    }

    private function hydrateProjection(stdClass $row): PublicationStatusProjection
    {
        return new PublicationStatusProjection(
            workspaceId: (string) $row->workspace_id,
            publicationAttemptId: (string) $row->publication_attempt_id,
            providerConnectionId: (string) $row->provider_connection_id,
            capabilityEvidenceId: (string) $row->capability_evidence_id,
            providerId: (string) $row->provider_id,
            providerOperationId: (string) $row->provider_operation_id,
            normalizedStatus: ProviderOperationStatus::from((string) $row->normalized_status),
            providerStatus: (string) $row->provider_status,
            providerObservedAt: $this->utc((string) $row->provider_observed_at),
            source: ReconciliationSource::from((string) $row->reconciliation_source),
            sourceReference: (string) $row->source_reference,
            currentObservationId: (string) $row->current_observation_id,
            currentObservationHash: (string) $row->current_observation_hash,
            projectionVersion: (int) $row->projection_version,
            updatedAt: $this->utc((string) $row->updated_at),
        );
    }

    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Publication status evidence cannot be encoded as JSON.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('Publication status evidence must be JSON.');
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Publication status evidence contains invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException('Publication status evidence must decode to an array.');
        }

        return $decoded;
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
