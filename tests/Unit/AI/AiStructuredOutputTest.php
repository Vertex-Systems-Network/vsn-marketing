<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Domain\AiSchemaValidator;
use App\Modules\AI\Domain\AiStructuredOutputValidator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AiStructuredOutputTest extends TestCase
{
    private function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['workspace_id', 'reference_ids', 'decision'],
            'properties' => [
                'workspace_id' => ['type' => 'string', 'maxLength' => 64],
                'reference_ids' => ['type' => 'array', 'maxItems' => 4, 'items' => ['type' => 'string', 'maxLength' => 64]],
                'decision' => ['type' => 'string', 'maxLength' => 16, 'enum' => ['DRAFT', 'RECOMMEND']],
            ]];
    }

    public function test_validates_only_complete_tenant_bound_known_reference_proposals(): void
    {
        $scope = new TenantContext('org', 'workspace', null, 'actor');
        $validator = new AiStructuredOutputValidator;
        $result = ['status' => 'complete', 'schema_id' => 'proposal.v1',
            'output' => ['workspace_id' => 'workspace', 'reference_ids' => ['known'], 'decision' => 'DRAFT']];
        self::assertSame('validated', $validator->validate($result, 'proposal.v1', $this->schema(), $scope, ['known'])['status']);
        foreach ([['status' => 'refused'], ['status' => 'incomplete'], ['status' => 'cancelled'], ['schema_id' => 'unknown'],
            ['output' => array_replace($result['output'], ['workspace_id' => 'foreign'])],
            ['output' => array_replace($result['output'], ['reference_ids' => ['invented']])],
            ['output' => array_replace($result['output'], ['decision' => 'SEND'])],
            ['output' => array_replace($result['output'], ['permission' => 'admin'])],
        ] as $override) {
            try {
                $validator->validate(array_replace($result, $override), 'proposal.v1', $this->schema(), $scope, ['known']);
                self::fail('Invalid proposal accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_rejects_sensitive_values_unbounded_and_unsupported_schemas(): void
    {
        $validator = new AiSchemaValidator;
        foreach ([
            ['password=private', ['type' => 'string', 'maxLength' => 100]],
            ['alice@example.org', ['type' => 'string', 'maxLength' => 100]],
            ['safe', ['type' => 'string']],
            ['safe', ['type' => 'string', 'maxLength' => 20, 'pattern' => '.*']],
            [[1, 2], ['type' => 'array', 'maxItems' => 1, 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10]]],
            [1.5, ['type' => 'integer', 'minimum' => 0, 'maximum' => 10]],
            [['extra' => true], ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false]],
        ] as [$value, $schema]) {
            try {
                $validator->validate($value, $schema);
                self::fail('Unsafe schema accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
