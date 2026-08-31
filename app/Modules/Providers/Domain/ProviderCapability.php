<?php

namespace App\Modules\Providers\Domain;

use DateTimeImmutable;

final readonly class ProviderCapability
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $providerId,
        public ?string $connectionId,
        public string $operation,
        public CapabilitySupport $support,
        public array $requiredScopes,
        public array $requiredRoles,
        public array $constraints,
        public string $sourceUrl,
        public ?string $sourceVersion,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $freshUntil,
    ) {}
}
