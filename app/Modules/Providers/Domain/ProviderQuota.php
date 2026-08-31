<?php

namespace App\Modules\Providers\Domain;

use DateTimeImmutable;

final readonly class ProviderQuota
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $providerId,
        public ?string $connectionId,
        public string $operation,
        public string $scopeType,
        public ?string $scopeReference,
        public string $unit,
        public string $windowType,
        public ?int $windowSeconds,
        public ?string $region,
        public ?string $principalType,
        public ?string $principalReference,
        public ?string $accountTier,
        public ?string $limitValue,
        public ?string $usedValue,
        public ?string $remainingValue,
        public ?DateTimeImmutable $resetsAt,
        public bool $dynamicallyDiscovered,
        public ?string $discoveryKey,
        public array $metadata,
        public string $sourceUrl,
        public ?string $sourceVersion,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $freshUntil,
    ) {}
}
