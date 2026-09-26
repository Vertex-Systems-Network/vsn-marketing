<?php

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Application\DeterministicSegmentCompiler;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0044 PostgreSQL compiler tests.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('TASK-0044 compiler persistence certification requires PostgreSQL.');
    }
});

function task0044PgWorkspace(string $label): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $suffix = Str::lower(Str::random(8));

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => $label,
        'slug' => $label.'-'.$suffix,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => $label,
        'slug' => $label.'-'.$suffix,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['organization_id' => $organizationId, 'workspace_id' => $workspaceId];
}

function task0044PgContact(string $workspaceId, ?string $companyId = null): string
{
    $id = (string) Str::uuid();
    DB::table('contacts')->insert([
        'id' => $id,
        'workspace_id' => $workspaceId,
        'brand_id' => null,
        'company_id' => $companyId,
        'first_name' => null,
        'last_name' => null,
        'display_name' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function task0044PgCompany(string $workspaceId, string $domain): string
{
    $id = (string) Str::uuid();
    DB::table('companies')->insert([
        'id' => $id,
        'workspace_id' => $workspaceId,
        'brand_id' => null,
        'name' => 'Example',
        'domain' => $domain,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function task0044PgContext(array $workspace): TenantContext
{
    return new TenantContext(
        $workspace['organization_id'],
        $workspace['workspace_id'],
        null,
        (string) Str::uuid(),
    );
}

it('keeps compiled company predicates workspace-scoped on PostgreSQL and permits re-entrant migration', function () {
    $inside = task0044PgWorkspace('inside');
    $outside = task0044PgWorkspace('outside');
    $hostileDomain = "example.test' OR 1=1 --";
    $insideContact = task0044PgContact(
        $inside['workspace_id'],
        task0044PgCompany($inside['workspace_id'], $hostileDomain),
    );
    task0044PgContact(
        $outside['workspace_id'],
        task0044PgCompany($outside['workspace_id'], $hostileDomain),
    );

    $definition = [
        'schema_version' => 1,
        'subject' => 'contact',
        'root' => [
            'type' => 'group',
            'operator' => 'all',
            'children' => [[
                'type' => 'attribute',
                'field' => 'company.domain',
                'operator' => 'equals',
                'value' => $hostileDomain,
            ]],
        ],
    ];
    $compiled = app(DeterministicSegmentCompiler::class)->compile(
        $definition,
        task0044PgContext($inside),
        new DateTimeImmutable('2026-09-26T12:00:00Z'),
    );

    expect($compiled->query->pluck('c.id')->all())->toBe([$insideContact])
        ->and($compiled->query->getBindings())->toContain($hostileDomain);

    $migration = require database_path('migrations/2026_09_26_000001_create_segment_definition_tables.php');
    $migration->up();

    expect(DB::getSchemaBuilder()->hasTable('segment_definitions'))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasTable('segment_definition_versions'))->toBeTrue();
});

it('rejects a canonical event name registered only in another PostgreSQL workspace', function () {
    $inside = task0044PgWorkspace('event-inside');
    $outside = task0044PgWorkspace('event-outside');
    DB::table('event_types')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $outside['workspace_id'],
        'canonical_name' => 'foreign.event',
        'schema_version' => 1,
        'created_at' => now(),
    ]);
    $definition = [
        'schema_version' => 1,
        'subject' => 'contact',
        'root' => [
            'type' => 'group',
            'operator' => 'all',
            'children' => [[
                'type' => 'event',
                'name' => 'foreign.event',
                'mode' => 'exists',
                'window' => ['kind' => 'relative', 'days' => 30],
            ]],
        ],
    ];

    expect(fn () => app(DeterministicSegmentCompiler::class)->compile(
        $definition,
        task0044PgContext($inside),
        new DateTimeImmutable('2026-09-26T12:00:00Z'),
    ))->toThrow(SegmentDefinitionException::class, 'unknown_or_foreign_event');
});
