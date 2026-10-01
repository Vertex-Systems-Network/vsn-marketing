<?php

namespace App\Modules\AI\Domain;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final class AiStructuredOutputValidator
{
    public function __construct(private readonly AiSchemaValidator $schemas = new AiSchemaValidator) {}

    /** Schema and known references are selected by trusted application code. */
    public function validate(array $result, string $schemaId, array $schema, TenantContext $scope, array $knownReferences): array
    {
        $output = $result['output'] ?? null;
        if (($result['status'] ?? null) !== 'complete' || ($result['schema_id'] ?? null) !== $schemaId
            || ! is_array($output) || ($output['workspace_id'] ?? null) !== $scope->workspaceId
            || ! is_array($output['reference_ids'] ?? null) || ! array_is_list($output['reference_ids'])
        ) {
            throw new InvalidArgumentException('AI proposal status, schema or references rejected.');
        }
        $this->schemas->validate($output, $schema);
        if (array_diff($output['reference_ids'], $knownReferences) !== []) {
            throw new InvalidArgumentException('AI proposal references rejected.');
        }

        return ['status' => 'validated', 'schema_id' => $schemaId,
            'workspace_id' => $scope->workspaceId, 'output' => $output,
            'output_sha256' => hash('sha256', json_encode($output, JSON_THROW_ON_ERROR))];
    }
}
