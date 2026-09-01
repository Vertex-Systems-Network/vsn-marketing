<?php

namespace App\Modules\Providers\Domain\Connectors;

use DateTimeImmutable;

final readonly class ProviderOperationObservation
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $providerOperationId,
        public ProviderOperationStatus $status,
        public ReconciliationSource $source,
        public DateTimeImmutable $observedAt,
        public array $evidence = [],
    ) {}
}
