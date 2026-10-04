<?php

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Application\DeterministicSegmentCompiler;
use Illuminate\Database\QueryException;
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

it('cancels over-budget PostgreSQL work with a transaction-local statement timeout', function () {
    expect(fn () => DB::transaction(function (): void {
        DB::statement('SET LOCAL statement_timeout = 10');
        DB::select('SELECT pg_sleep(0.05)');
    }))->toThrow(QueryException::class);
});

it('explains representative bounded query shape with tenant equality and bound values', function () {
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    DB::table('organizations')->insert(['id' => $organizationId, 'name' => 'Preview fixture',
        'slug' => 'preview-fixture', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspaces')->insert(['id' => $workspaceId, 'organization_id' => $organizationId,
        'name' => 'Preview fixture', 'slug' => 'preview-fixture', 'created_at' => now(), 'updated_at' => now()]);
    $companyId = (string) Str::uuid();
    DB::table('companies')->insert(['id' => $companyId, 'workspace_id' => $workspaceId, 'brand_id' => null,
        'name' => 'Fixture', 'domain' => "x' OR 1=1 --", 'created_at' => now(), 'updated_at' => now()]);
    for ($i = 0; $i < 300; $i++) {
        DB::table('contacts')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
            'brand_id' => null, 'company_id' => $companyId, 'first_name' => null,
            'last_name' => null, 'display_name' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $definition = ['schema_version' => 1, 'subject' => 'contact', 'root' => [
        'type' => 'group', 'operator' => 'all', 'children' => [[
            'type' => 'attribute', 'field' => 'company.domain', 'operator' => 'equals', 'value' => "x' OR 1=1 --",
        ]],
    ]];
    $compiled = app(DeterministicSegmentCompiler::class)->compile($definition,
        new TenantContext($organizationId, $workspaceId, null, (string) Str::uuid()),
        new DateTimeImmutable('2026-09-26T12:00:00Z'));
    $bounded = DB::query()->fromSub((clone $compiled->query)->limit(251), 'bounded_members')->selectRaw('count(*) as aggregate');
    $plan = DB::select('EXPLAIN (FORMAT JSON) '.$bounded->toSql(), $bounded->getBindings());
    $shape = json_encode($plan, JSON_THROW_ON_ERROR);

    expect($bounded->toSql())->toContain('workspace_id')->not->toContain("x' OR 1=1 --")
        ->and($bounded->getBindings())->toContain("x' OR 1=1 --")
        ->and($shape)->toContain('Plan')
        ->and((int) $bounded->first()->aggregate)->toBe(251);
});
