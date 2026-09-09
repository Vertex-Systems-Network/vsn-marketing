<?php

namespace App\Modules\DeliveryEngine\Domain;

use InvalidArgumentException;

final readonly class DeliveryCircuitBreakerKey
{
    public function __construct(
        public string $workspaceId,
        public string $providerConnectionId,
        public string $operationClass,
    ) {
        if (trim($workspaceId) === '') {
            throw new InvalidArgumentException('Circuit breaker workspace id must not be empty.');
        }

        if (trim($providerConnectionId) === '') {
            throw new InvalidArgumentException('Circuit breaker provider connection id must not be empty.');
        }

        if (trim($operationClass) === '') {
            throw new InvalidArgumentException('Circuit breaker operation class must not be empty.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            $this->workspaceId,
            $this->providerConnectionId,
            $this->operationClass,
        ]));
    }
}
