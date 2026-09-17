<?php

namespace App\Modules\DeliveryEngine\Infrastructure\Deliverability;

use App\Modules\DeliveryEngine\Domain\Deliverability\DeliverabilityObservation;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class DeliverabilityObservationRepository
{
    /** @var array<string, DeliverabilityObservation> */
    private array $byId = [];

    /** @var array<string, string> */
    private array $replayIndex = [];

    public function append(DeliverabilityObservation $observation): DeliverabilityObservation
    {
        $replayIdentity = $this->replayIdentity($observation->workspaceId, $observation->replayKey);
        $existingId = $this->replayIndex[$replayIdentity] ?? null;

        if ($existingId !== null) {
            $stored = $this->byId[$existingId];
            $this->assertReplay($stored, $observation);

            return $stored;
        }

        $existingById = $this->byId[$observation->id] ?? null;

        if ($existingById instanceof DeliverabilityObservation) {
            if ($existingById->workspaceId !== $observation->workspaceId) {
                throw new AuthorizationException('Deliverability observation access denied.');
            }

            throw new InvalidArgumentException('Deliverability observation ID already exists with a different replay identity.');
        }

        $this->byId[$observation->id] = $observation;
        $this->replayIndex[$replayIdentity] = $observation->id;

        return $observation;
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

        $observations = array_values(array_filter(
            $this->byId,
            static fn (DeliverabilityObservation $observation): bool =>
                $observation->workspaceId === $workspaceId
                && ($providerKey === null || $observation->providerKey === $providerKey)
                && ($messagePurpose === null || $observation->messagePurpose === $messagePurpose),
        ));

        usort(
            $observations,
            static fn (DeliverabilityObservation $left, DeliverabilityObservation $right): int =>
                [$left->observedAt->getTimestamp(), $left->recordedAt->getTimestamp(), $left->id]
                <=> [$right->observedAt->getTimestamp(), $right->recordedAt->getTimestamp(), $right->id],
        );

        return $observations;
    }

    private function replayIdentity(string $workspaceId, string $replayKey): string
    {
        return $workspaceId."\0".$replayKey;
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
            || $stored->effectiveAt != $candidate->effectiveAt
            || $stored->observedAt != $candidate->observedAt
            || $stored->recordedAt != $candidate->recordedAt
            || $stored->freshUntil != $candidate->freshUntil
            || $stored->trusted !== $candidate->trusted
        ) {
            throw new InvalidArgumentException('Deliverability observation replay key conflicts with different evidence.');
        }
    }
}
