<?php

namespace App\Modules\DeliveryEngine\Domain\Reputation;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReputationHealthEvidence
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $providerKey,
        public string $source,
        public string $version,
        public ReputationHealthStatus $status,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $observedAt,
        public DateTimeImmutable $freshUntil,
        public bool $trusted,
    ) {
        foreach ([
            'id' => $id,
            'workspaceId' => $workspaceId,
            'providerKey' => $providerKey,
            'source' => $source,
            'version' => $version,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must be non-blank.');
            }
        }

        if ($observedAt < $effectiveAt) {
            throw new InvalidArgumentException('observedAt must be on or after effectiveAt.');
        }

        if ($freshUntil < $observedAt) {
            throw new InvalidArgumentException('freshUntil must be on or after observedAt.');
        }
    }
}
