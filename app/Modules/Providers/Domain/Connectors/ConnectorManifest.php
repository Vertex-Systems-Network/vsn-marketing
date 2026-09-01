<?php

namespace App\Modules\Providers\Domain\Connectors;

use App\Modules\Providers\Domain\CapabilitySupport;
use DateTimeImmutable;

final readonly class ConnectorManifest
{
    /**
     * @param  list<string>  $sandboxLimitations
     * @param  list<ConnectorCapability>  $capabilities
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $connectorKey,
        public string $connectorVersion,
        public string $apiVersionStrategy,
        public string $documentationUrl,
        public DateTimeImmutable $observedAt,
        public ?string $sourceVersion = null,
        public ?DateTimeImmutable $deprecatedAt = null,
        public ?DateTimeImmutable $sunsetAt = null,
        public array $sandboxLimitations = [],
        public array $capabilities = [],
        public array $metadata = [],
    ) {}

    public function capability(string $operation): ConnectorCapability
    {
        foreach ($this->capabilities as $capability) {
            if ($capability->operation === $operation) {
                return $capability;
            }
        }

        return new ConnectorCapability(
            operation: $operation,
            support: CapabilitySupport::Unknown,
            readinessStates: [],
        );
    }

    public function isDeprecatedAt(DateTimeImmutable $at): bool
    {
        return $this->deprecatedAt !== null && $this->deprecatedAt <= $at;
    }
}
