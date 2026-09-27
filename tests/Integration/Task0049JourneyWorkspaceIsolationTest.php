<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL) !== true
        || DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run TASK-0049 PostgreSQL tenant-isolation tests.');
    }
});

function task0049PgWorkspace(string $label): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $slug = $label.'-'.Str::lower(Str::random(8));

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => $label,
        'slug' => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => $label,
        'slug' => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $workspaceId;
}

it('rejects cross-workspace enrollment, execution, and node-attempt references', function () {
    $inside = task0049PgWorkspace('journey-inside');
    $outside = task0049PgWorkspace('journey-outside');
    $journeyId = (string) Str::uuid();
    $versionId = (string) Str::uuid();

    DB::table('journeys')->insert([
        'id' => $journeyId,
        'workspace_id' => $inside,
        'name' => 'Tenant journey',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $version = [
        'id' => $versionId,
        'workspace_id' => $inside,
        'journey_id' => $journeyId,
        'version_number' => 1,
        'graph' => '{}',
        'definition_hash' => str_repeat('a', 64),
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    expect(fn () => DB::transaction(fn () => DB::table('journey_versions')->insert([...$version, 'id' => (string) Str::uuid(), 'workspace_id' => $outside])))
        ->toThrow(QueryException::class);
    DB::table('journey_versions')->insert($version);

    $enrollmentId = (string) Str::uuid();
    $enrollment = [
        'id' => $enrollmentId,
        'workspace_id' => $inside,
        'journey_version_id' => $versionId,
        'subject_id' => (string) Str::uuid(),
        'trigger_event_id' => (string) Str::uuid(),
        'enrollment_key' => str_repeat('d', 64),
        'generation' => 1,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    expect(fn () => DB::transaction(fn () => DB::table('journey_enrollments')->insert([...$enrollment, 'id' => (string) Str::uuid(), 'workspace_id' => $outside])))
        ->toThrow(QueryException::class);
    DB::table('journey_enrollments')->insert($enrollment);

    $executionId = (string) Str::uuid();
    $execution = [
        'id' => $executionId,
        'workspace_id' => $inside,
        'journey_version_id' => $versionId,
        'subject_id' => (string) Str::uuid(),
        'enrollment_id' => (string) Str::uuid(),
        'execution_key' => str_repeat('b', 64),
        'status' => 'queued',
        'revision' => 0,
        'transition_history' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    expect(fn () => DB::transaction(fn () => DB::table('journey_executions')->insert([...$execution, 'id' => (string) Str::uuid(), 'workspace_id' => $outside])))
        ->toThrow(QueryException::class);
    DB::table('journey_executions')->insert($execution);

    expect(fn () => DB::transaction(fn () => DB::table('journey_node_attempts')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $outside,
        'execution_id' => $executionId,
        'node_id' => 'node-1',
        'attempt' => 1,
        'attempt_key' => str_repeat('c', 64),
        'status' => 'queued',
        'lease_until' => null,
        'error' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});
