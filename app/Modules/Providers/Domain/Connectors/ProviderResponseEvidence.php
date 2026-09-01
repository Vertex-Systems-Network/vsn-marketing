<?php

namespace App\Modules\Providers\Domain\Connectors;

final readonly class ProviderResponseEvidence
{
    /**
     * @param  array<string, string|list<string>>  $headers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $httpStatus,
        public array $headers = [],
        public ?string $providerRequestId = null,
        public array $metadata = [],
    ) {}
}
