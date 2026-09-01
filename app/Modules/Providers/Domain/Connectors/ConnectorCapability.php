<?php

namespace App\Modules\Providers\Domain\Connectors;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderReadinessStatus;

final readonly class ConnectorCapability
{
    /**
     * @param  list<ProviderReadinessStatus>  $readinessStates
     * @param  array<string, mixed>  $constraints
     */
    public function __construct(
        public string $operation,
        public CapabilitySupport $support,
        public array $readinessStates,
        public array $constraints = [],
    ) {}

    public function isUsableAt(ProviderReadinessStatus $readiness): bool
    {
        return $this->support === CapabilitySupport::Supported
            && in_array($readiness, $this->readinessStates, true);
    }
}
