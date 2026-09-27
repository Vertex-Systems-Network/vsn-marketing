<?php

use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Journeys\Domain\Contracts\JourneyWaitRepository;
use App\Modules\Journeys\Domain\DurableJourneyWait;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyRuntimePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function persistedJourneyWaitFixture(): array
{
    $slug = 'journey-wait-'.Str::lower(Str::random(10));
    $organization = Organization::query()->create(['name' => $slug, 'slug' => $slug]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => $slug,
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
        'name' => 'Wait persistence',
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

function scheduledJourneyWait(array $fixture, int $seconds, ?DateTimeImmutable $now = null): DurableJourneyWait
{
    return DurableJourneyWait::schedule(
        $fixture['workspace_id'],
        $fixture['execution_id'],
        'delay-node',
        $now ?? new DateTimeImmutable('2026-09-27T12:00:00Z'),
        $seconds,
        new JourneyRuntimePolicy,
    );
}

it('stores waits idempotently and rejects reuse of a wait key with different predicate data', function () {
    $fixture = persistedJourneyWaitFixture();
    $wait = scheduledJourneyWait($fixture, 60);
    $repository = app(JourneyWaitRepository::class);

    expect($repository->store($wait, ['field' => 'score', 'operator' => 'greater_than', 'value' => 10]))->toBeTrue()
        ->and($repository->store($wait, ['value' => 10, 'operator' => 'greater_than', 'field' => 'score']))->toBeFalse()
        ->and(DB::table('journey_waits')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(1);

    expect(fn () => $repository->store($wait, ['field' => 'score', 'operator' => 'greater_than', 'value' => 11]))
        ->toThrow(JourneyDefinitionException::class, 'wait_idempotency_conflict');
});

it('returns only bounded due waits for the requested workspace in deterministic order', function () {
    $first = persistedJourneyWaitFixture();
    $second = persistedJourneyWaitFixture();
    $now = new DateTimeImmutable('2026-09-27T12:00:00Z');
    $repository = app(JourneyWaitRepository::class);
    $early = scheduledJourneyWait($first, 10, $now);
    $late = DurableJourneyWait::schedule(
        $first['workspace_id'], $first['execution_id'], 'later-node', $now, 20, new JourneyRuntimePolicy,
    );
    $otherWorkspace = scheduledJourneyWait($second, 10, $now);
    $repository->store($late, null);
    $repository->store($early, ['field' => 'ready', 'operator' => 'exists']);
    $repository->store($otherWorkspace, null);

    $due = $repository->due($first['workspace_id'], new DateTimeImmutable('2026-09-27T12:00:15Z'), 50);
    expect($due)->toHaveCount(1)
        ->and($due[0]->wait->idempotencyKey)->toBe($early->idempotencyKey)
        ->and($due[0]->predicate)->toBe(['field' => 'ready', 'operator' => 'exists'])
        ->and($repository->due($first['workspace_id'], new DateTimeImmutable('2026-09-27T13:00:00Z'), 0))->toHaveCount(0);
});

it('enforces the composite workspace execution foreign key', function () {
    $inside = persistedJourneyWaitFixture();
    $outside = persistedJourneyWaitFixture();
    $wait = scheduledJourneyWait([
        'workspace_id' => $outside['workspace_id'],
        'execution_id' => $inside['execution_id'],
    ], 60);

    expect(fn () => app(JourneyWaitRepository::class)->store($wait))
        ->toThrow(QueryException::class);
});

it('keeps migration creation idempotent and refuses rollback while waits exist', function () {
    Schema::drop('journey_waits');
    $migration = require base_path('database/migrations/2026_09_27_000002_create_journey_waits_table.php');
    $migration->up();
    $migration->up();
    expect(Schema::hasTable('journey_waits'))->toBeTrue();

    $fixture = persistedJourneyWaitFixture();
    app(JourneyWaitRepository::class)->store(scheduledJourneyWait($fixture, 60));

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'persisted wait state must be preserved');
    expect(DB::getSchemaBuilder()->hasTable('journey_waits'))->toBeTrue();
});
