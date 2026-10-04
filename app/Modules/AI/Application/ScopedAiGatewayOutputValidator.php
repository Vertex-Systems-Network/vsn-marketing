<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\AiStructuredOutputValidator;
use App\Modules\AI\Domain\Contracts\AiGatewayOutputValidator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

/** Constructed from an authorized context manifest and an immutable server schema. */
final class ScopedAiGatewayOutputValidator implements AiGatewayOutputValidator
{
    public function __construct(
        private readonly string $schemaId,
        private readonly array $schema,
        private readonly string $contextHash,
        private readonly array $knownReferences,
        private readonly AiStructuredOutputValidator $validator = new AiStructuredOutputValidator,
    ) {}

    public function validate(array $result, array $request, TenantContext $scope): array
    {
        if (($request['output_schema_id'] ?? null) !== $this->schemaId
            || ($request['context_manifest_sha256'] ?? null) !== $this->contextHash
            || ! preg_match('/^[a-f0-9]{64}$/D', $this->contextHash)) {
            throw new InvalidArgumentException('AI validation context changed.');
        }

        return $this->validator->validate($result, $this->schemaId, $this->schema, $scope, $this->knownReferences);
    }
}
