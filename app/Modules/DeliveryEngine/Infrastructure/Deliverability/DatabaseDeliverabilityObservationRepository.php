<?php

namespace App\Modules\DeliveryEngine\Infrastructure\Deliverability;

use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilityObservation;
use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilitySignalKind;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use stdClass;

final readonly class DatabaseDeliverabilityObservationRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function append(DeliverabilityObservation $observation): DeliverabilityObservation
    {
        return $this->database->connection()->transaction(function () use ($observation): DeliverabilityObservation {
            $existingReplay = $this->database->connection()->table('deliverability_observations')
                ->where('workspace_id', $observation->workspaceId)
                ->where('replay_key', $observation->replayKey)
                ->lockForUpdate()
                ->first();

            if ($existingReplay instanceof stdClass) {
                $stored = $this->hydrate($existingReplay);
                $this->assertReplay($stored, $observation);

                return $stored;
            }

            $existingId = $this->database->connection()->table('deliverability_observations')
                ->where('id', $observation->id)
                ->lockForUpdate()
                ->first();

            if ($existingId instanceof stdClass) {
                $this->failForExistingId($existingId, $observation);
            }

            $inserted = $this->database->connection()->table('deliverability_observations')
                ->insertOrIgnore($this->payload($observation));

            if ($inserted === 1) {
                return $observation;
            }

            $raceReplay = $this->database->connection()->table('deliverability_observations')
                ->where('workspace_id', $observation->workspaceId)
                ->where('replay_key', $observation->replayKey)
                ->first();

            if ($raceReplay instanceof stdClass) {
                $stored = $this->hydrate($raceReplay);
                $this->assertReplay($stored, $observation);

                return $stored;
            }

            $raceId = $this->database->connection()->table('deliverability_observations')
                ->where('id', $observation->id)
                ->first();

            if ($raceId instanceof stdClass) {
                $this->failForExistingId($raceId, $observation);
            }

            throw new InvalidArgumentException('Deliverability observation could not be persisted.');
        });
    }

    /** @return list<DeliverabilityObservation> */
    public function observations(
        string $workspaceId,
        ?string $providerKey = null,
        ?string $messagePurpose = null,
    ): array {
        if (trim($workspaceId) === '') {
            throw new InvalidArgumentException('workspaceId must be non-blank.');
        }

        if ($providerKey !== null && trim($providerKey) === '') {
            throw new InvalidArgumentException('providerKey must be non-blank when provided.');
        }

        if ($messagePurpose !== null && trim($messagePurpose) === '') {
            throw new InvalidArgumentException('messagePurpose must be non-blank when provided.');
        }

        $query = $this->database->connection()->table('deliverability_observations')
            ->where('workspace_id', $workspaceId);

        if ($providerKey !== null) {
            $query->where('provider_key', $providerKey);
        }

        if ($messagePurpose !== null) {
            $query->where('message_purpose', $messagePurpose);
        }

        return $query
            ->orderBy('observed_at')
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): DeliverabilityObservation => $this->hydrate($row))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function payload(DeliverabilityObservation $observation): array
    {
        return [
            'id' => $observation->id,
            'workspace_id' => $observation->workspaceId,
            'provider_key' => $observation->providerKey,
            'source' => $observation->source,
            'evidence_version' => $observation->version,
            'message_purpose' => $observation->messagePurpose,
            'signal_kind' => $observation->kind->value,
            'signal_key' => $observation->signalKey,
            'signal_value' => $observation->signalValue,
            'provenance_reference' => $observation->provenanceReference,
            'replay_key' => $observation->replayKey,
            'effective_at' => $observation->effectiveAt,
            'observed_at' => $observation->observedAt,
            'recorded_at' => $observation->recordedAt,
            'fresh_until' => $observation->freshUntil,
            'trusted' => $observation->trusted,
        ];
    }

    private function hydrate(stdClass $row): DeliverabilityObservation
    {
        return new DeliverabilityObservation(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            providerKey: (string) $row->provider_key,
            source: (string) $row->source,
            version: (string) $row->evidence_version,
            messagePurpose: (string) $row->message_purpose,
            kind: DeliverabilitySignalKind::from((string) $row->signal_kind),
            signalKey: (string) $row->signal_key,
            signalValue: (string) $row->signal_value,
            provenanceReference: (string) $row->provenance_reference,
            replayKey: (string) $row->replay_key,
            effectiveAt: new DateTimeImmutable((string) $row->effective_at),
            observedAt: new DateTimeImmutable((string) $row->observed_at),
            recordedAt: new DateTimeImmutable((string) $row->recorded_at),
            freshUntil: $row->fresh_until === null ? null : new DateTimeImmutable((string) $row->fresh_until),
            trusted: (bool) $row->trusted,
        );
    }

    private function failForExistingId(stdClass $row, DeliverabilityObservation $candidate): never
    {
        if ((string) $row->workspace_id !== $candidate->workspaceId) {
            throw new AuthorizationException('Deliverability observation access denied.');
        }

        throw new InvalidArgumentException(
            'Deliverability observation ID already exists with a different replay identity.',
        );
    }

    private function assertReplay(
        DeliverabilityObservation $stored,
        DeliverabilityObservation $candidate,
    ): void {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->providerKey !== $candidate->providerKey
            || $stored->source !== $candidate->source
            || $stored->version !== $candidate->version
            || $stored->messagePurpose !== $candidate->messagePurpose
            || $stored->kind !== $candidate->kind
            || $stored->signalKey !== $candidate->signalKey
            || $stored->signalValue !== $candidate->signalValue
            || $stored->provenanceReference !== $candidate->provenanceReference
            || $stored->replayKey !== $candidate->replayKey
            || $stored->effectiveAt != $candidate->effectiveAt
            || $stored->observedAt != $candidate->observedAt
            || $stored->recordedAt != $candidate->recordedAt
            || $stored->freshUntil != $candidate->freshUntil
            || $stored->trusted !== $candidate->trusted
        ) {
            throw new InvalidArgumentException(
                'Deliverability observation replay key conflicts with different evidence.',
            );
        }
    }
}
