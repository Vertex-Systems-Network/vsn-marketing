<?php

namespace App\Modules\Providers\Domain\Connectors;

final readonly class ProviderFailureEvidence
{
    /**
     * @param  array<string, string|list<string>>  $headers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $message,
        public ?string $providerCode = null,
        public ?int $httpStatus = null,
        public array $headers = [],
        public array $metadata = [],
    ) {}
}
