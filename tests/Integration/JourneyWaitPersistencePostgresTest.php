<?php

use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Journeys\Domain\Contracts\JourneyWaitRepository;
use App\Modules\Journeys\Domain\DurableJourneyWait;
use App\Modules\Journeys\Domain\JourneyRuntimePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL infrastructure integration is required for durable wait tenant-FK validation.');
    }
});

function createJourneyWaitPostgresExecution(string $label): array
{
    $slug = $label.'-'.Str::lower(Str::random(8));
    $organization = Organization::query()->create(['name' => $label, 'slug' => $slug]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => $label,
        'slug' => $slug,
    ]);
    $workspaceId = (string) $workspace->getKey();
    $journeyId = (string) Str::uuid();
    $versionId = (string) Str::uuid();
    $executionId = (string) Str::uuid();
    $now = now();

    DB::table('journeys')->insert([
        'id' => $journeyId,
        'workspace_id' => $workspaceId,
        'name' => $label,
        'status' => 'published',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('journey_versions')->insert([
        'id' => $versionId,
        'workspace_id' => $workspaceId,
        'journey_id' => $journeyId,
        'version_number' => 1,
        'graph' => '{}',
        'definition_hash' => hash('sha256', $journeyId),
        'status' => 'published',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('journey_executions')->insert([
        'id' => $executionId,
        'workspace_id' => $workspaceId,
        'journey_version_id' => $versionId,
        'subject_id' => (string) Str::uuid(),
        'enrollment_id' => (string) Str::uuid(),
        'execution_key' => hash('sha256', $executionId),
        'status' => 'running',
        'revision' => 0,
        'transition_history' => '[]',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['workspace_id' => $workspaceId, 'execution_id' => $executionId];
}

it('rejects a persisted wait that pairs one workspace with another workspace execution', function () {
    $inside = createJourneyWaitPostgresExecution('wait-fk-inside');
    $outside = createJourneyWaitPostgresExecution('wait-fk-outside');
    $wait = DurableJourneyWait::schedule(
        $outside['workspace_id'],
        $inside['execution_id'],
        'delay-node',
        new DateTimeImmutable('2026-09-27T12:00:00Z'),
        60,
        new JourneyRuntimePolicy,
    );

    expect(fn () => app(JourneyWaitRepository::class)->store($wait))
        ->toThrow(QueryException::class);
});
