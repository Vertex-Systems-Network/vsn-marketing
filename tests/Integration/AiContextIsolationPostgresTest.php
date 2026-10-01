<?php

use App\Modules\AI\Application\AiContextAssembler;
use App\Modules\AI\Domain\AiContextSanitizer;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Infrastructure\DatabaseAiContextRepository;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL integration environment is required.');
    }
});

it('enforces exact nullable scope predicates and deletion on PostgreSQL', function () {
    $organization = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    $other = (string) Str::uuid();
    DB::table('organizations')->insert(['id' => $organization, 'name' => 'AI context integration',
        'slug' => 'ai-context-'.Str::random(12), 'created_at' => now(), 'updated_at' => now()]);
    foreach ([$workspace, $other] as $id) {
        DB::table('workspaces')->insert(['id' => $id, 'organization_id' => $organization,
            'name' => 'AI context integration', 'slug' => 'ai-context-'.Str::random(12),
            'created_at' => now(), 'updated_at' => now()]);
    }
    $permission = new class implements AiContextPermission
    {
        public function allows(TenantContext $scope, string $permission): bool
        {
            return in_array($permission, ['ai.execute', 'contact.read'], true);
        }
    };
    $repository = new DatabaseAiContextRepository($permission, new AiContextSanitizer);
    $assembler = new AiContextAssembler($repository, $permission, new AiContextSanitizer);
    $at = new DateTimeImmutable('2026-10-01T12:00:00Z');
    $scope = new TenantContext($organization, $workspace, null, (string) Str::uuid());
    $source = ['source_kind' => 'approved_fact', 'classification' => 'approved_non_personal',
        'permission' => 'contact.read', 'content' => 'Approved analytics interest.',
        'provenance_reference' => 'contact:revision-3', 'revision' => 'v3',
        'expires_at' => $at->modify('+1 day')];
    $id = $repository->put($scope, null, null, $source, $at);
    $foreign = new TenantContext($organization, $other, null, $scope->actorId);

    expect($assembler->assemble($scope, null, null, [$id], $at)['manifest']['sources'][0]['revision'])->toBe('v3')
        ->and(fn () => $assembler->assemble($foreign, null, null, [$id], $at))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $assembler->assemble($scope, (string) Str::uuid(), null, [$id], $at))->toThrow(InvalidArgumentException::class)
        ->and($repository->delete($foreign, null, null, $id))->toBeFalse()
        ->and($repository->delete($scope, null, null, $id))->toBeTrue()
        ->and(DB::table('ai_context_memories')->where('id', $id)->value('content'))->toBe('');
});
