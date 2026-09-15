<?php

namespace App\Modules\DeliveryEngine\Domain\SenderAuthentication;

use DateTimeImmutable;

final readonly class AuthenticationDecision
{
    public function __construct(
        public string $workspaceId,
        public string $senderDomainId,
        public AuthenticationReadiness $readiness,
        public array $reasons,
        public array $evidenceVersions,
        public DateTimeImmutable $decidedAt,
    ) {}

    public function isReady(): bool
    {
        return $this->readiness === AuthenticationReadiness::Ready;
    }
}
