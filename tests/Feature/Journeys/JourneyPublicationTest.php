<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Journeys\Application\EnrollSubjectInJourney;
use App\Modules\Journeys\Application\JourneyRegistry;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyReentryPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function journeyPublisherFixture(): array
{
    $user = User::query()->create([
        'name' => 'Journey Publisher',
        'email' => 'journey-publisher@example.test',
        'password' => Hash::make('test-password'),
    ]);
    $organization = Organization::query()->create(['name' => 'Journey Org', 'slug' => 'journey-org']);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Journey Workspace',
        'slug' => 'journey-workspace',
    ]);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $workspace->getKey());
    $role = $roles->createRole((string) $workspace->getKey(), 'journey-publisher', 'Journey Publisher');
    $roles->grantPermission($role, PermissionCatalog::JOURNEY_PUBLISH);
    $roles->assignRole($membership, $role);
    $journeyId = (string) Str::uuid();
    DB::table('journeys')->insert([
        'id' => $journeyId,
        'workspace_id' => $workspace->getKey(),
        'name' => 'Welcome',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $scope = new TenantContext(
        organizationId: (string) $organization->getKey(),
        workspaceId: (string) $workspace->getKey(),
        brandId: null,
        actorId: (string) $user->getKey(),
    );

    return compact('user', 'workspace', 'journeyId', 'scope');
}

function publishableJourneyGraph(): array
{
    return [
        'schema_version' => 1,
        'nodes' => [
            ['id' => 'entry', 'type' => 'trigger', 'config' => ['event' => 'customer.created']],
            ['id' => 'finish', 'type' => 'end'],
        ],
        'edges' => [['from' => 'entry', 'to' => 'finish']],
    ];
}

it('publishes a workspace-authorized canonical immutable version', function () {
    $fixture = journeyPublisherFixture();
    $result = app(JourneyRegistry::class)->publish(
        $fixture['journeyId'], 1, $fixture['scope'], $fixture['user'], publishableJourneyGraph(), true,
    );
    $version = DB::table('journey_versions')->where('id', $result['definition']->versionId)->first();

    expect($version)->not->toBeNull()
        ->and($version->workspace_id)->toBe($fixture['scope']->workspaceId)
        ->and($version->definition_hash)->toBe($result['definition']->hash)
        ->and($version->status)->toBe('published')
        ->and(DB::table('audit_events')->where('action', 'journey.version.published')
            ->where('subject_id', $fixture['journeyId'])->count())->toBe(1);

    expect(fn () => app(JourneyRegistry::class)->publish(
        $fixture['journeyId'], 1, $fixture['scope'], $fixture['user'], publishableJourneyGraph(), true,
    ))->toThrow(InvalidArgumentException::class, 'cannot be republished');
});

it('rejects publication without explicit confirmation or workspace authority', function () {
    $fixture = journeyPublisherFixture();
    expect(fn () => app(JourneyRegistry::class)->publish(
        $fixture['journeyId'], 1, $fixture['scope'], $fixture['user'], publishableJourneyGraph(), false,
    ))->toThrow(AuthorizationException::class);

    DB::table('workspace_role_permissions')->delete();
    expect(fn () => app(JourneyRegistry::class)->publish(
        $fixture['journeyId'], 1, $fixture['scope'], $fixture['user'], publishableJourneyGraph(), true,
    ))->toThrow(AuthorizationException::class);
});

it('deduplicates trigger delivery and enforces explicit re-entry policy on pinned versions', function () {
    $fixture = journeyPublisherFixture();
    $published = app(JourneyRegistry::class)->publish(
        $fixture['journeyId'], 1, $fixture['scope'], $fixture['user'], publishableJourneyGraph(), true,
    );
    $enrollments = app(EnrollSubjectInJourney::class);
    $subjectId = (string) Str::uuid();
    $eventId = (string) Str::uuid();
    $first = $enrollments->enroll(
        $fixture['scope'], $published['definition']->versionId, $subjectId, $eventId,
    );
    $redelivery = $enrollments->enroll(
        $fixture['scope'], $published['definition']->versionId, $subjectId, $eventId,
    );

    expect($first['duplicate'])->toBeFalse()
        ->and($redelivery['duplicate'])->toBeTrue()
        ->and($redelivery['id'])->toBe($first['id'])
        ->and(DB::table('journey_enrollments')->where('subject_id', $subjectId)->count())->toBe(1);

    expect(fn () => $enrollments->enroll(
        $fixture['scope'], $published['definition']->versionId, $subjectId, (string) Str::uuid(),
    ))->toThrow(JourneyDefinitionException::class, 'reentry_not_allowed');

    DB::table('journey_enrollments')->where('id', $first['id'])->update(['status' => 'exited']);
    $afterExitVersion = app(JourneyRegistry::class)->publish(
        $fixture['journeyId'], 2, $fixture['scope'], $fixture['user'], publishableJourneyGraph(), true,
        JourneyReentryPolicy::AfterExit,
    );
    $firstOnVersionTwo = $enrollments->enroll(
        $fixture['scope'], $afterExitVersion['definition']->versionId, $subjectId, (string) Str::uuid(),
    );
    expect(fn () => $enrollments->enroll(
        $fixture['scope'], $afterExitVersion['definition']->versionId, $subjectId, (string) Str::uuid(),
    ))->toThrow(JourneyDefinitionException::class, 'reentry_not_allowed');
    DB::table('journey_enrollments')->where('id', $firstOnVersionTwo['id'])->update(['status' => 'exited']);
    $second = $enrollments->enroll(
        $fixture['scope'], $afterExitVersion['definition']->versionId, $subjectId, (string) Str::uuid(),
    );
    expect($second['generation'])->toBe(2)
        ->and($second['status'])->toBe('active')
        ->and($second['enrollment_key'])->not->toBe($first['enrollment_key']);

    $boundedVersion = app(JourneyRegistry::class)->publish(
        $fixture['journeyId'], 3, $fixture['scope'], $fixture['user'], publishableJourneyGraph(), true,
        JourneyReentryPolicy::Bounded, 1,
    );
    $boundedFirst = $enrollments->enroll(
        $fixture['scope'], $boundedVersion['definition']->versionId, $subjectId, (string) Str::uuid(),
    );
    expect($boundedFirst['generation'])->toBe(1);
    expect(fn () => $enrollments->enroll(
        $fixture['scope'], $boundedVersion['definition']->versionId, $subjectId, (string) Str::uuid(),
    ))->toThrow(JourneyDefinitionException::class, 'reentry_not_allowed');
});

it('refuses to enroll against a journey version owned by another workspace', function () {
    $fixture = journeyPublisherFixture();
    $published = app(JourneyRegistry::class)->publish(
        $fixture['journeyId'], 1, $fixture['scope'], $fixture['user'], publishableJourneyGraph(), true,
    );
    $foreignOrganization = Organization::query()->create(['name' => 'Foreign Org', 'slug' => 'foreign-org']);
    $foreignWorkspace = Workspace::query()->create([
        'organization_id' => $foreignOrganization->getKey(),
        'name' => 'Foreign Workspace',
        'slug' => 'foreign-workspace',
    ]);
    $foreignScope = new TenantContext(
        organizationId: (string) $foreignOrganization->getKey(),
        workspaceId: (string) $foreignWorkspace->getKey(),
        brandId: null,
        actorId: (string) $fixture['user']->getKey(),
    );

    expect(fn () => app(EnrollSubjectInJourney::class)->enroll(
        $foreignScope,
        $published['definition']->versionId,
        (string) Str::uuid(),
        (string) Str::uuid(),
    ))->toThrow(InvalidArgumentException::class, 'unavailable in this workspace');
});
