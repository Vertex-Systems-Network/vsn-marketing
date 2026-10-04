<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Journeys\Application\ReplayJourneyExecution;
use App\Modules\Journeys\Domain\Contracts\JourneyNodeAttemptRepository;
use App\Modules\Journeys\Domain\JourneyAttemptPolicy;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyExecutionIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function persistedNodeAttemptFixture(?string $workspaceId = null): array
{
    if ($workspaceId === null) {
        $slug = 'journey-attempt-'.Str::lower(Str::random(10));
        $organization = Organization::query()->create(['name' => $slug, 'slug' => $slug]);
        $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => $slug, 'slug' => $slug]);
        $workspaceId = (string) $workspace->getKey();
    }
    $journeyId = (string) Str::uuid();
    $versionId = (string) Str::uuid();
    $executionId = (string) Str::uuid();
    $now = now();
    DB::table('journeys')->insert(['id' => $journeyId, 'workspace_id' => $workspaceId, 'name' => 'Attempts', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('journey_versions')->insert(['id' => $versionId, 'workspace_id' => $workspaceId, 'journey_id' => $journeyId, 'version_number' => 1, 'graph' => '{}', 'definition_hash' => hash('sha256', $journeyId), 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('journey_executions')->insert(['id' => $executionId, 'workspace_id' => $workspaceId, 'journey_version_id' => $versionId, 'subject_id' => (string) Str::uuid(), 'enrollment_id' => (string) Str::uuid(), 'execution_key' => hash('sha256', $executionId), 'status' => 'queued', 'revision' => 0, 'transition_history' => '[]', 'created_at' => $now, 'updated_at' => $now]);

    return ['workspace_id' => $workspaceId, 'execution_id' => $executionId];
}

it('claims a deterministic node attempt once and fences stale lease holders', function () {
    $fixture = persistedNodeAttemptFixture();
    $repository = app(JourneyNodeAttemptRepository::class);
    $now = new DateTimeImmutable('2026-09-27T12:00:00Z');
    $claim = $repository->claim($fixture['workspace_id'], $fixture['execution_id'], 'send-email', 1, $now, new JourneyAttemptPolicy(maxWorkspaceConcurrent: 2, leaseSeconds: 30));

    expect($claim)->not->toBeNull()
        ->and($claim['attempt_key'])->toBe(JourneyExecutionIdentity::nodeAttempt($fixture['execution_id'], 'send-email', 1))
        ->and($repository->claim($fixture['workspace_id'], $fixture['execution_id'], 'send-email', 1, $now, new JourneyAttemptPolicy(maxWorkspaceConcurrent: 2, leaseSeconds: 30)))->toBeNull()
        ->and($repository->complete($fixture['workspace_id'], $fixture['execution_id'], $claim['attempt_key'], 'stale-token', $now->modify('+1 second')))->toBeFalse()
        ->and($repository->complete($fixture['workspace_id'], $fixture['execution_id'], $claim['attempt_key'], $claim['lease_token'], $now->modify('+1 second')))->toBeTrue()
        ->and(DB::table('journey_node_attempts')->where('attempt_key', $claim['attempt_key'])->value('status'))->toBe('succeeded')
        ->and(DB::table('journey_executions')->where('id', $fixture['execution_id'])->value('status'))->toBe('running')
        ->and(DB::table('journey_execution_transitions')->where('execution_id', $fixture['execution_id'])->orderBy('transition_revision')->pluck('event_type')->all())->toBe(['attempt_claimed', 'attempt_succeeded']);
});

it('reclaims an expired lease with a new fencing token and records bounded retry state', function () {
    $fixture = persistedNodeAttemptFixture();
    $repository = app(JourneyNodeAttemptRepository::class);
    $now = new DateTimeImmutable('2026-09-27T12:00:00Z');
    $policy = new JourneyAttemptPolicy(maxWorkspaceConcurrent: 2, leaseSeconds: 10, maxAttempts: 2);
    $first = $repository->claim($fixture['workspace_id'], $fixture['execution_id'], 'condition', 1, $now, $policy);
    $reclaimed = $repository->claim($fixture['workspace_id'], $fixture['execution_id'], 'condition', 1, $now->modify('+11 seconds'), $policy);

    expect($reclaimed)->not->toBeNull()->and($reclaimed['lease_token'])->not->toBe($first['lease_token'])
        ->and($repository->complete($fixture['workspace_id'], $fixture['execution_id'], $first['attempt_key'], $first['lease_token'], $now->modify('+12 seconds')))->toBeFalse()
        ->and($repository->fail($fixture['workspace_id'], $fixture['execution_id'], $reclaimed['attempt_key'], $reclaimed['lease_token'], ['code' => 'temporary'], true, false, $policy, $now->modify('+12 seconds')))->toBe('retryable')
        ->and(DB::table('journey_node_attempts')->where('attempt_key', $first['attempt_key'])->value('available_at'))->not->toBeNull();
    $secondAttempt = $repository->claim($fixture['workspace_id'], $fixture['execution_id'], 'condition', 2, $now->modify('+28 seconds'), $policy);
    expect($secondAttempt)->not->toBeNull()
        ->and($secondAttempt['attempt_key'])->not->toBe($first['attempt_key'])
        ->and($repository->fail($fixture['workspace_id'], $fixture['execution_id'], $secondAttempt['attempt_key'], $secondAttempt['lease_token'], ['code' => 'temporary'], true, false, $policy, $now->modify('+29 seconds')))->toBe('dead_letter');
});

it('routes unknown outcomes to operator review and cancellation fences late completion', function () {
    $fixture = persistedNodeAttemptFixture();
    $repository = app(JourneyNodeAttemptRepository::class);
    $now = new DateTimeImmutable('2026-09-27T12:00:00Z');
    $policy = new JourneyAttemptPolicy(maxWorkspaceConcurrent: 2);
    $claim = $repository->claim($fixture['workspace_id'], $fixture['execution_id'], 'provider-action', 1, $now, $policy);

    expect($repository->fail($fixture['workspace_id'], $fixture['execution_id'], $claim['attempt_key'], $claim['lease_token'], ['code' => 'timeout', 'message' => 'password=secret'], true, true, $policy, $now->modify('+1 second')))->toBe('operator_review')
        ->and(DB::table('journey_node_attempts')->where('attempt_key', $claim['attempt_key'])->value('error_class'))->toBe('unknown_outcome')
        ->and(DB::table('journey_node_attempts')->where('attempt_key', $claim['attempt_key'])->value('error'))->not->toContain('secret')
        ->and(DB::table('journey_execution_transitions')->where('execution_id', $fixture['execution_id'])->orderByDesc('transition_revision')->value('event_type'))->toBe('attempt_operator_review');

    $nextExecution = persistedNodeAttemptFixture();
    $active = $repository->claim($nextExecution['workspace_id'], $nextExecution['execution_id'], 'wait', 1, $now, $policy);
    expect($repository->cancelExecution($nextExecution['workspace_id'], $nextExecution['execution_id'], $now->modify('+2 seconds')))->toBeTrue()
        ->and($repository->complete($nextExecution['workspace_id'], $nextExecution['execution_id'], $active['attempt_key'], $active['lease_token'], $now->modify('+3 seconds')))->toBeFalse()
        ->and(DB::table('journey_node_attempts')->where('attempt_key', $active['attempt_key'])->value('status'))->toBe('cancelled');
});

it('rejects unsafe retry, lease, and delay budgets', function () {
    expect(fn () => new JourneyAttemptPolicy(maxWorkspaceConcurrent: 1, leaseSeconds: 0))->toThrow(JourneyDefinitionException::class)
        ->and(fn () => new JourneyAttemptPolicy(maxWorkspaceConcurrent: 1, maxAttempts: 21))->toThrow(JourneyDefinitionException::class)
        ->and(fn () => new JourneyAttemptPolicy(maxWorkspaceConcurrent: 1, retryDelaySeconds: 86401))->toThrow(JourneyDefinitionException::class);
});

it('enforces a serialized workspace concurrency budget and releases capacity on completion', function () {
    $first = persistedNodeAttemptFixture();
    $second = persistedNodeAttemptFixture($first['workspace_id']);
    $repository = app(JourneyNodeAttemptRepository::class);
    $policy = new JourneyAttemptPolicy(maxWorkspaceConcurrent: 1, leaseSeconds: 60);
    $now = new DateTimeImmutable('2026-09-27T12:00:00Z');
    $claim = $repository->claim($first['workspace_id'], $first['execution_id'], 'node-a', 1, $now, $policy);

    expect($claim)->not->toBeNull()
        ->and($repository->claim($second['workspace_id'], $second['execution_id'], 'node-b', 1, $now, $policy))->toBeNull()
        ->and($repository->complete($first['workspace_id'], $first['execution_id'], $claim['attempt_key'], $claim['lease_token'], $now->modify('+1 second')))->toBeTrue()
        ->and($repository->claim($second['workspace_id'], $second['execution_id'], 'node-b', 1, $now->modify('+2 seconds'), $policy))->not->toBeNull();
});

function replayActorFixture(string $workspaceId, bool $grant): User
{
    $actor = User::query()->create([
        'name' => 'Journey Replay Operator',
        'email' => 'journey-replay-'.Str::lower(Str::random(12)).'@example.test',
        'password' => 'test-password',
    ]);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($actor, $workspaceId);
    $role = $roles->createRole($workspaceId, 'journey-replay-'.Str::lower(Str::random(8)), 'Journey Replay');
    if ($grant) {
        $roles->grantPermission($role, PermissionCatalog::JOURNEY_REPLAY);
    }
    $roles->assignRole($membership, $role);

    return $actor;
}

it('replays only an authorized terminal execution against its pinned version idempotently', function () {
    $fixture = persistedNodeAttemptFixture();
    DB::table('journey_executions')->where('id', $fixture['execution_id'])->update(['status' => 'failed']);
    $organizationId = (string) Workspace::query()->whereKey($fixture['workspace_id'])->value('organization_id');
    $actor = replayActorFixture($fixture['workspace_id'], true);
    $tenant = new TenantContext($organizationId, $fixture['workspace_id'], null, (string) $actor->getKey());
    $service = app(ReplayJourneyExecution::class);

    $first = $service->handle($tenant, $actor, $fixture['execution_id'], 'operator-request-1');
    $duplicate = $service->handle($tenant, $actor, $fixture['execution_id'], 'operator-request-1');
    $source = DB::table('journey_executions')->where('id', $fixture['execution_id'])->first();
    $replay = DB::table('journey_executions')->where('id', $first['id'])->first();

    expect($first['duplicate'])->toBeFalse()
        ->and($duplicate['duplicate'])->toBeTrue()
        ->and($duplicate['id'])->toBe($first['id'])
        ->and($replay->journey_version_id)->toBe($source->journey_version_id)
        ->and(DB::table('journey_execution_transitions')->where('execution_id', $first['id'])->value('event_type'))->toBe('execution_replayed')
        ->and(DB::table('journey_execution_transitions')->where('execution_id', $first['id'])->value('metadata'))->toContain('source_transition_revision');
});

it('rejects cross-workspace and non-terminal replay despite valid workspace permission', function () {
    $source = persistedNodeAttemptFixture();
    $other = persistedNodeAttemptFixture();
    $otherOrganizationId = (string) Workspace::query()->whereKey($other['workspace_id'])->value('organization_id');
    $sourceOrganizationId = (string) Workspace::query()->whereKey($source['workspace_id'])->value('organization_id');
    $otherActor = replayActorFixture($other['workspace_id'], true);
    $sourceActor = replayActorFixture($source['workspace_id'], true);
    $otherScope = new TenantContext($otherOrganizationId, $other['workspace_id'], null, (string) $otherActor->getKey());
    $sourceScope = new TenantContext($sourceOrganizationId, $source['workspace_id'], null, (string) $sourceActor->getKey());
    $service = app(ReplayJourneyExecution::class);

    expect(fn () => $service->handle($otherScope, $otherActor, $source['execution_id'], 'request-cross-tenant'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->handle($sourceScope, $sourceActor, $source['execution_id'], 'request-running'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('journey_executions')->where('workspace_id', $source['workspace_id'])->count())->toBe(1);
});

it('does not create a replay without scoped operator permission or with a mismatched actor', function () {
    $fixture = persistedNodeAttemptFixture();
    DB::table('journey_executions')->where('id', $fixture['execution_id'])->update(['status' => 'failed']);
    $organizationId = (string) Workspace::query()->whereKey($fixture['workspace_id'])->value('organization_id');
    $actor = replayActorFixture($fixture['workspace_id'], false);
    $tenant = new TenantContext($organizationId, $fixture['workspace_id'], null, (string) $actor->getKey());
    $authorizedActor = replayActorFixture($fixture['workspace_id'], true);
    $service = app(ReplayJourneyExecution::class);

    expect(fn () => $service->handle($tenant, $actor, $fixture['execution_id'], 'request-denied'))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->handle($tenant, $authorizedActor, $fixture['execution_id'], 'request-mismatch'))->toThrow(AuthorizationException::class)
        ->and(DB::table('journey_executions')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(1);
});
