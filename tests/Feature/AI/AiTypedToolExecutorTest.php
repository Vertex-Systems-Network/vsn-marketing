<?php

use App\Modules\AI\Application\AiTypedToolExecutor;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiToolApproval;
use App\Modules\AI\Domain\Contracts\AiToolHandler;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('authorizes typed tools independently and binds durable replays to actor and arguments', function () {
    $scope = aiContextScope();
    $permissions = new class implements AiContextPermission
    {
        public bool $allow = true;

        public function allows(TenantContext $scope, string $permission): bool
        {
            return $this->allow && in_array($permission, ['ai.execute', 'contact.read'], true);
        }
    };
    $approval = new class implements AiToolApproval
    {
        public function approved(TenantContext $scope, string $toolId, string $argumentsHash, ?string $reference): bool
        {
            return false;
        }
    };
    $handler = new class implements AiToolHandler
    {
        public int $calls = 0;

        public function execute(TenantContext $scope, array $arguments): array
        {
            $this->calls++;

            return ['decision' => 'DRAFT'];
        }
    };
    $args = ['type' => 'object', 'additionalProperties' => false, 'required' => ['reference'],
        'properties' => ['reference' => ['type' => 'string', 'maxLength' => 64, 'enum' => ['known', 'second']]]];
    $result = ['type' => 'object', 'additionalProperties' => false, 'required' => ['decision'],
        'properties' => ['decision' => ['type' => 'string', 'maxLength' => 16, 'enum' => ['DRAFT']]]];
    $registry = json_decode(file_get_contents(base_path('.ai/ai/TOOL-REGISTRY.yaml')), true, flags: JSON_THROW_ON_ERROR)['tools'];
    $definition = ['version' => 'v1', 'risk' => 'R0', 'effect' => 'proposal', 'permission' => 'contact.read',
        'handler' => $handler, 'arguments_schema' => $args, 'result_schema' => $result];
    $executor = new AiTypedToolExecutor($permissions, $approval, app(IdempotentExecutor::class), app(AuditRecorder::class),
        $registry, ['propose_campaign' => $definition]);
    expect($executor->execute($scope, 'propose_campaign', ['reference' => 'known'], 'R0', 'key-a')['status'])->toBe('executed')
        ->and($executor->execute($scope, 'propose_campaign', ['reference' => 'known'], 'R0', 'key-a')['status'])->toBe('executed')
        ->and($handler->calls)->toBe(1)
        ->and(fn () => $executor->execute($scope, 'propose_campaign', ['reference' => 'second'], 'R0', 'key-a'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $executor->execute($scope, 'shell', [], 'R0', 'key-b'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $executor->execute($scope, 'propose_campaign', ['reference' => 'invented'], 'R0', 'key-c'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $executor->execute($scope, 'propose_campaign', ['reference' => 'known'], 'R3', 'key-d', 'model-says-approved'))->toThrow(InvalidArgumentException::class);
    $permissions->allow = false;
    expect(fn () => $executor->execute($scope, 'propose_campaign', ['reference' => 'known'], 'R0', 'key-a'))->toThrow(InvalidArgumentException::class)
        ->and($handler->calls)->toBe(1)
        ->and(DB::table('audit_events')->where('workspace_id', $scope->workspaceId)->where('action', 'ai.tool.authorized')->count())->toBeGreaterThan(0);
});
