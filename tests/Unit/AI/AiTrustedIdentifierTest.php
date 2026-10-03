<?php

use App\Modules\AI\Domain\AiSchemaValidator;
use App\Modules\AI\Domain\AiStructuredOutputValidator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;

it('accepts exact trusted UUID references without exempting prose enum or identifier bounds', function () {
    $id = '12345678-1234-4123-8123-123456789012';
    $scope = new TenantContext('org', $id, null, 'actor');
    $schema = ['type' => 'object', 'properties' => [
        'workspace_id' => ['type' => 'string', 'maxLength' => 36],
        'reference_ids' => ['type' => 'array', 'maxItems' => 1, 'items' => ['type' => 'string', 'maxLength' => 36]],
        'text' => ['type' => 'string', 'maxLength' => 128],
    ], 'required' => ['workspace_id', 'reference_ids', 'text'], 'additionalProperties' => false];
    $result = ['status' => 'complete', 'schema_id' => 'fixture', 'output' => ['workspace_id' => $id, 'reference_ids' => [$id], 'text' => 'Measured count: 1.']];
    $validator = new AiStructuredOutputValidator;
    expect($validator->validate($result, 'fixture', $schema, $scope, [$id])['output'])->toBe($result['output']);
    $hostile = $result;
    $hostile['output']['text'] = $id;
    expect(fn () => $validator->validate($hostile, 'fixture', $schema, $scope, [$id]))->toThrow(InvalidArgumentException::class);
    $bounded = $schema;
    $bounded['properties']['workspace_id']['maxLength'] = 12;
    expect(fn () => $validator->validate($result, 'fixture', $bounded, $scope, [$id]))->toThrow(InvalidArgumentException::class);
    $enumerated = $schema;
    $enumerated['properties']['workspace_id']['enum'] = ['different'];
    expect(fn () => $validator->validate($result, 'fixture', $enumerated, $scope, [$id]))->toThrow(InvalidArgumentException::class);
    $hostile['output']['reference_ids'] = ['forged'];
    expect(fn () => $validator->validate($hostile, 'fixture', $schema, $scope, [$id]))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new AiSchemaValidator)->validate($id, ['type' => 'string', 'maxLength' => 36]))->toThrow(InvalidArgumentException::class);
});
