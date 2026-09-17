<?php

namespace App\Modules\DeliveryEngine\Domain\Deliverability;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliverabilityObservation
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $providerKey,
        public string $source,
        public string $version,
        public string $messagePurpose,
        public DeliverabilitySignalKind $kind,
        public string $signalKey,
        public string $signalValue,
        public string $provenanceReference,
        public string $replayKey,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $recordedAt,
        public ?DateTimeImmutable $freshUntil = null,
        public bool $trusted = false,
    ) {
        foreach ([
            'id' => $id,
            'workspaceId' => $workspaceId,
            'providerKey' => $providerKey,
            'source' => $source,
            'version' => $version,
            'messagePurpose' => $messagePurpose,
            'signalKey' => $signalKey,
            'signalValue' => $signalValue,
            'provenanceReference' => $provenanceReference,
            'replayKey' => $replayKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must be non-blank.');
            }
        }

        if ($observedAt < $effectiveAt) {
            throw new InvalidArgumentException('observedAt must be on or after effectiveAt.');
        }

        if ($recordedAt < $observedAt) {
            throw new InvalidArgumentException('recordedAt must be on or after observedAt.');
        }

        if ($freshUntil !== null && $freshUntil < $observedAt) {
            throw new InvalidArgumentException('freshUntil must be on or after observedAt when provided.');
        }
    }

    public function isStaleAt(DateTimeImmutable $evaluatedAt): bool
    {
        return $this->freshUntil !== null && $evaluatedAt > $this->freshUntil;
    }
}
