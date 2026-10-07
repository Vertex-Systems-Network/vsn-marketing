<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

final readonly class IngestedConnectorDescription
{
    /**
     * @param  array<string, mixed>|null  $document
     */
    public function __construct(
        public ConnectorSourceProvenance $provenance,
        public string $sourceType,
        public ?array $document,
        public string $normalizedText,
    ) {}

    public function isOpenApi(): bool
    {
        return $this->sourceType === 'openapi';
    }
}
