<?php

namespace App\Modules\Providers\Domain\Connectors;

use DateTimeImmutable;

final readonly class ProviderQuotaSignal
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $operation,
        public string $scopeType,
        public string $unit,
        public string $windowType,
        public string $sourceKey,
        public ?string $scopeReference = null,
        public ?int $windowSeconds = null,
        public ?string $region = null,
        public ?string $principalType = null,
        public ?string $principalReference = null,
        public ?string $accountTier = null,
        public ?string $limitValue = null,
        public ?string $remainingValue = null,
        public ?DateTimeImmutable $resetsAt = null,
        public array $evidence = [],
    ) {}
}
