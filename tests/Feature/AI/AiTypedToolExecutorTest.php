<?php

use App\Modules\AI\Application\AiTypedToolExecutor;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiReversibleToolHandler;
use App\Modules\AI\Domain\Contracts\AiToolApproval;
use App\Modules\AI\Domain\Contracts\AiToolHandler;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('authorizes typed tools independently and binds durable replays to actor and arguments', function () {
    $suffix = Str::lower(Str::random(10));
    $org = Organization::query()->create(['name' => 'Tools '.$suffix, 'slug' => 'tools-'.$suffix]);
    $workspace = Workspace::query()->create(['organization_id' => $org->getKey(), 'name' => 'Tools '.$suffix, 'slug' => 'tools-'.$suffix]);
    $scope = new TenantContext((string) $org->getKey(), (string) $workspace->getKey(), null, (string) Str::uuid());
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

it('requires independent write approval and rolls back a failed postcondition without logging raw errors', function () {
    $suffix = Str::lower(Str::random(10));
    $org = Organization::query()->create(['name' => 'Write '.$suffix, 'slug' => 'write-'.$suffix]);
    $workspace = Workspace::query()->create(['organization_id' => $org->getKey(), 'name' => 'Write '.$suffix, 'slug' => 'write-'.$suffix]);
    $scope = new TenantContext((string) $org->getKey(), (string) $workspace->getKey(), null, (string) Str::uuid());
    $permissions = new class implements AiContextPermission
    {
        public function allows(TenantContext $scope, string $permission): bool
        {
            return in_array($permission, ['ai.execute', 'contact.read'], true);
        }
    };
    $approval = new class($scope->actorId) implements AiToolApproval
    {
        public function __construct(private readonly string $actor) {}

        public function approved(TenantContext $scope, string $toolId, string $argumentsHash, ?string $reference): bool
        {
            return $scope->actorId === $this->actor && $reference === 'independent-review';
        }
    };
    $handler = new class implements AiReversibleToolHandler
    {
        public int $calls = 0;

        public int $rollbacks = 0;

        public function preflight(TenantContext $scope, array $arguments): bool
        {
            return true;
        }

        public function execute(TenantContext $scope, array $arguments): array
        {
            $this->calls++;

            return ['decision' => 'DRAFT'];
        }

        public function postcondition(TenantContext $scope, array $arguments, array $result): bool
        {
            return false;
        }

        public function rollback(TenantContext $scope, array $arguments): void
        {
            $this->rollbacks++;
        }
    };
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => []];
    $result = ['type' => 'object', 'additionalProperties' => false, 'required' => ['decision'],
        'properties' => ['decision' => ['type' => 'string', 'maxLength' => 16, 'enum' => ['DRAFT']]]];
    $registry = json_decode(file_get_contents(base_path('.ai/ai/TOOL-REGISTRY.yaml')), true, flags: JSON_THROW_ON_ERROR)['tools'];
    $definitions = ['generate_adapter_candidate' => ['version' => 'v1', 'risk' => 'R1', 'effect' => 'reversible_write',
        'permission' => 'contact.read', 'handler' => $handler, 'arguments_schema' => $schema, 'result_schema' => $result]];
    $executor = new AiTypedToolExecutor($permissions, $approval, app(IdempotentExecutor::class), app(AuditRecorder::class), $registry, $definitions);
    expect(fn () => $executor->execute($scope, 'generate_adapter_candidate', [], 'R1', 'write-a', 'model-approved'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $executor->execute($scope, 'generate_adapter_candidate', [], 'R0', 'write-b', 'independent-review'))->toThrow(InvalidArgumentException::class)
        ->and($handler->calls)->toBe(0)
        ->and(fn () => $executor->execute($scope, 'generate_adapter_candidate', [], 'R1', 'write-c', 'independent-review'))->toThrow(RuntimeException::class)
        ->and($handler->calls)->toBe(1)->and($handler->rollbacks)->toBe(1);
});
