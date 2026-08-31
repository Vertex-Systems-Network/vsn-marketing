<?php

declare(strict_types=1);

namespace App\Connectors\Contracts;

use App\Connectors\Manifest;

interface ConnectorInterface
{
    /**
     * Return connector manifest describing capabilities and provenance
     */
    public function manifest(): Manifest;

    /**
     * Perform a provider operation. Implementations should normalize errors via ErrorNormalizerInterface.
     * @param string $operation
     * @param array $params
     * @return mixed
     */
    public function perform(string $operation, array $params = []);

    /**
     * Optional: return provider operation id for async operations when available
     */
    public function operationId(mixed $result): ?string;
}
