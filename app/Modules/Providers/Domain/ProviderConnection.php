<?php

namespace App\Modules\Providers\Domain;

use DateTimeImmutable;

final readonly class ProviderConnection
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $providerId,
        public string $name,
        public ProviderReadinessStatus $readiness,
        public AuthFamily $authFamily,
        public SecretReference $secretReference,
        public array $requestedScopes,
        public array $grantedScopes,
        public array $roles,
        public ?string $accessTier,
        public ?string $region,
        public ?string $principalType,
        public ?string $principalReference,
        public ?string $providerReviewStatus,
        public ?DateTimeImmutable $tokenExpiresAt,
        public bool $refreshSupported,
        public ?DateTimeImmutable $lastRotatedAt,
        public array $metadata,
        public string $sourceUrl,
        public ?string $sourceVersion,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $freshUntil,
    ) {}
}
