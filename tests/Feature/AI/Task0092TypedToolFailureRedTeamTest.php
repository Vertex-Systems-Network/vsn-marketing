<?php

use App\Modules\AI\Application\AiTypedToolExecutor;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiReversibleToolHandler;
use App\Modules\AI\Domain\Contracts\AiToolApproval;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('holds a failed provider-like AI tool safely and redacts raw handler and rollback exceptions', function () {
    $suffix = Str::lower(Str::random(10));
    $org = Organization::query()->create([
        'name' => 'Red-team tool failure '.$suffix, 'slug' => 'redteam-tool-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $org->getKey(), 'name' => 'Red-team tool failure '.$suffix,
        'slug' => 'redteam-tool-'.$suffix,
    ]);
    $scope = new TenantContext(
        (string) $org->getKey(), (string) $workspace->getKey(), null, (string) Str::uuid(),
    );

    $permissions = new class implements AiContextPermission
    {
        public function allows(TenantContext $scope, string $permission): bool
        {
            return in_array($permission, ['ai.execute', 'contact.read'], true);
        }
    };
    $approval = new class implements AiToolApproval
    {
        public function approved(TenantContext $scope, string $toolId, string $argumentsHash, ?string $reference): bool
        {
            return $reference === 'independent-human-approval';
        }
    };
    $handler = new class implements AiReversibleToolHandler
    {
        public int $calls = 0;

        public int $rollbacks = 0;

        public bool $rollbackFails = false;

        public function preflight(TenantContext $scope, array $arguments): bool
        {
            return true;
        }

        public function execute(TenantContext $scope, array $arguments): array
        {
            $this->calls++;

            throw new RuntimeException('provider queue failure: RAW_PROVIDER_SECRET_0092');
        }

        public function postcondition(TenantContext $scope, array $arguments, array $result): bool
        {
            return true;
        }

        public function rollback(TenantContext $scope, array $arguments): void
        {
            $this->rollbacks++;
            if ($this->rollbackFails) {
                throw new RuntimeException('failed remote rollback: RAW_ROLLBACK_SECRET_0092');
            }
        }
    };

    $args = [
        'type' => 'object', 'additionalProperties' => false,
        'required' => [], 'properties' => [],
    ];
    $results = [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['decision'],
        'properties' => [
            'decision' => ['type' => 'string', 'enum' => ['DRAFT']],
        ],
    ];
    $registry = json_decode(
        file_get_contents(base_path('.ai/ai/TOOL-REGISTRY.yaml')), true, flags: JSON_THROW_ON_ERROR,
    )['tools'];
    $definitions = [
        'generate_adapter_candidate' => [
            'version' => 'v1', 'risk' => 'R1', 'effect' => 'reversible_write',
            'permission' => 'contact.read', 'handler' => $handler,
            'arguments_schema' => $args, 'result_schema' => $results,
        ],
    ];
    $executor = new AiTypedToolExecutor(
        $permissions, $approval, app(IdempotentExecutor::class),
        app(AuditRecorder::class), $registry, $definitions,
    );

    expect(fn () => $executor->execute($scope, 'generate_adapter_candidate', [], 'R1', 'denied-1', 'model-self-approved'))
        ->toThrow(InvalidArgumentException::class)
        ->and($handler->calls)->toBe(0);

    try {
        $executor->execute($scope, 'generate_adapter_candidate', [], 'R1', 'failure-1', 'independent-human-approval');
        test()->fail('Failed provider-like call must never become an execution result.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('AI tool execution or validation failed.')
            ->not->toContain('RAW_PROVIDER_SECRET_0092');
    }
    expect($handler->calls)->toBe(1)
        ->and($handler->rollbacks)->toBe(1);

    $handler->rollbackFails = true;
    try {
        $executor->execute($scope, 'generate_adapter_candidate', [], 'R1', 'failure-2', 'independent-human-approval');
        test()->fail('Failed rollback must require operator recovery.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('AI tool rollback requires operator recovery.')
            ->not->toContain('RAW_ROLLBACK_SECRET_0092')
            ->not->toContain('RAW_PROVIDER_SECRET_0092');
    }

    expect($handler->calls)->toBe(2)
        ->and($handler->rollbacks)->toBe(2);

    // No original provider or rollback exception should escape into the
    // append-only audit stream. Only normalized, bounded error classes remain.
    $events = DB::table('audit_events')
        ->where('workspace_id', $scope->workspaceId)->pluck('evidence')->all();
    $serialized = implode('\n', array_map(static fn ($item): string => (string) $item, $events));
    expect($events)->not->toBeEmpty()
        ->and($serialized)->not->toContain('RAW_PROVIDER_SECRET_0092')
        ->and($serialized)->not->toContain('RAW_ROLLBACK_SECRET_0092')
        ->and($serialized)->toContain('ai-tool:generate_adapter_candidate:v1');
});
