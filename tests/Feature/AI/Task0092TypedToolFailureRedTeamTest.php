<?php

use App\Modules\AI\Application\AiTypedToolExecutor;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiReversibleToolHandler;
use App\Modules\AI\Domain\Contracts\AiToolApproval;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Core\Domain\Idempotency\IdempotencyHoldRequired;
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

    // The failed rollback is a durable human hold: neither the same key nor a
    // fresh key under the same approval may re-run the partially applied write.
    $handler->rollbackFails = false;
    foreach (['failure-2', 'failure-3'] as $retryKey) {
        try {
            $executor->execute($scope, 'generate_adapter_candidate', [], 'R1', $retryKey, 'independent-human-approval');
            test()->fail('A held AI tool must not execute again before operator recovery.');
        } catch (IdempotencyHoldRequired $exception) {
            expect($exception->getMessage())->toContain('held for operator recovery');
        }
    }

    expect($handler->calls)->toBe(2)
        ->and($handler->rollbacks)->toBe(2)
        ->and(DB::table('idempotency_keys')->where('workspace_id', $scope->workspaceId)
            ->where('idempotency_key', 'failure-2')->value('status'))->toBe('held')
        ->and(DB::table('idempotency_keys')->where('workspace_id', $scope->workspaceId)
            ->where('idempotency_key', 'failure-3')->exists())->toBeFalse()
        ->and(DB::table('audit_events')->where('workspace_id', $scope->workspaceId)
            ->where('action', IdempotentExecutor::AUDIT_HELD)->count())->toBe(1);

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

it('redacts raw preflight exceptions before they reach audit or idempotency evidence', function () {
    $suffix = Str::lower(Str::random(10));
    $org = Organization::query()->create([
        'name' => 'Red-team preflight '.$suffix, 'slug' => 'redteam-preflight-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $org->getKey(), 'name' => 'Red-team preflight '.$suffix,
        'slug' => 'redteam-preflight-'.$suffix,
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

        public function preflight(TenantContext $scope, array $arguments): bool
        {
            throw new RuntimeException('provider preflight failure: RAW_PREFLIGHT_SECRET_0092');
        }

        public function execute(TenantContext $scope, array $arguments): array
        {
            $this->calls++;

            return ['decision' => 'DRAFT'];
        }

        public function postcondition(TenantContext $scope, array $arguments, array $result): bool
        {
            return true;
        }

        public function rollback(TenantContext $scope, array $arguments): void {}
    };

    $registry = json_decode(
        file_get_contents(base_path('.ai/ai/TOOL-REGISTRY.yaml')), true, flags: JSON_THROW_ON_ERROR,
    )['tools'];
    $definitions = [
        'generate_adapter_candidate' => [
            'version' => 'v1', 'risk' => 'R1', 'effect' => 'reversible_write',
            'permission' => 'contact.read', 'handler' => $handler,
            'arguments_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => []],
            'result_schema' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['decision'],
                'properties' => ['decision' => ['type' => 'string', 'enum' => ['DRAFT']]],
            ],
        ],
    ];
    $executor = new AiTypedToolExecutor(
        $permissions, $approval, app(IdempotentExecutor::class),
        app(AuditRecorder::class), $registry, $definitions,
    );

    try {
        $executor->execute($scope, 'generate_adapter_candidate', [], 'R1', 'preflight-1', 'independent-human-approval');
        test()->fail('A throwing preflight must never execute the tool.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('AI tool preflight failed.');
    }

    $evidence = implode('\n', array_map(static fn ($item): string => (string) $item,
        DB::table('audit_events')->where('workspace_id', $scope->workspaceId)->pluck('evidence')->all()));
    $errors = implode('\n', array_map(static fn ($item): string => (string) $item,
        DB::table('idempotency_keys')->where('workspace_id', $scope->workspaceId)->pluck('last_error')->all()));

    expect($handler->calls)->toBe(0)
        ->and($evidence)->not->toContain('RAW_PREFLIGHT_SECRET_0092')
        ->and($errors)->not->toContain('RAW_PREFLIGHT_SECRET_0092')
        ->and($errors)->toContain('AI tool preflight failed.');
});
