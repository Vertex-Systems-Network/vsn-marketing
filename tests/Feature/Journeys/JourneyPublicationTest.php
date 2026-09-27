<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use App\Modules\Journeys\Application\JourneyRegistry;
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
        ->and($version->status)->toBe('published');

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
