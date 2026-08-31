<?php

declare(strict_types=1);

namespace App\Connectors\Adapters;

use App\Connectors\Contracts\ConnectorInterface;
use App\Connectors\Contracts\ErrorNormalizerInterface;
use App\Connectors\Manifest;
use App\Connectors\Enums\ErrorCategory;

/**
 * Example provider adapter skeleton. Adapt to real provider SDKs/HTTP clients.
 */
class ExampleProviderAdapter implements ConnectorInterface
{
    protected ErrorNormalizerInterface $normalizer;

    public function __construct(ErrorNormalizerInterface $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    public function manifest(): Manifest
    {
        return new Manifest([
            'name' => 'example-provider',
            'capabilities' => [
                'webhooks' => true,
                'asyncOperations' => true,
                'quotaSignals' => true,
            ],
        ], [
            'api' => 'https://api.example.com',
            'version' => 'v1',
            'notes' => 'Example adapter for TASK-0016 scaffolding',
        ]);
    }

    public function perform(string $operation, array $params = [])
    {
        // Placeholder: call provider SDK / HTTP endpoint here.
        // Simulate a provider error for tests by returning an array/object representing error.
        return ['status' => 'ok', 'result' => null];
    }

    public function operationId(mixed $result): ?string
    {
        // Example: provider returns operation id in result
        if (is_array($result) && isset($result['operation_id'])) {
            return (string) $result['operation_id'];
        }

        return null;
    }
}
