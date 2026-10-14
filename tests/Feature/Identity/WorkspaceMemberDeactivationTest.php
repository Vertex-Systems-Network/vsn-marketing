<?php

use App\Modules\Audit\Domain\Contracts\AuditEventRepository;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Provisioning\DeactivateWorkspaceMember;
use App\Modules\Identity\Application\Tenancy\TenantContextResolver;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('deactivates only the requested workspace with one redacted audit event and idempotent replay', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $first = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $second = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $user = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $roles->addMember($user, (string) $first->getKey());
    $roles->addMember($user, (string) $second->getKey());
    $service = app(DeactivateWorkspaceMember::class);
    $workspaceId = (string) $first->getKey();
    $userId = (string) $user->getKey();
    $arguments = [$workspaceId, $workspaceId, $userId, true, true, ['user.deactivate'], 'scoped-principal'];

    expect($service->execute(...$arguments))->toBeTrue()
        ->and($service->execute(...$arguments))->toBeFalse();

    expect(fn () => app(TenantContextResolver::class)->resolve($user, $workspaceId))
        ->toThrow(AuthorizationException::class);
    expect(app(TenantContextResolver::class)->resolve($user, (string) $second->getKey())->workspaceId)
        ->toBe((string) $second->getKey());

    $events = DB::table('audit_events')->where('workspace_id', $workspaceId)
        ->where('action', 'identity.provisioning.member_deactivated')->get();
    expect($events)->toHaveCount(1);
    expect(json_decode($events[0]->evidence, true))->toBe([
        'principal_fingerprint' => hash('sha256', 'scoped-principal'),
    ]);
});

it('denies disabled unverified cross-workspace and unscoped deactivation without writes', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $user = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    app(WorkspaceRoleManager::class)->addMember($user, (string) $workspace->getKey());
    $service = app(DeactivateWorkspaceMember::class);
    $workspaceId = (string) $workspace->getKey();
    $userId = (string) $user->getKey();

    foreach ([
        ['other-workspace', $workspaceId, $userId, true, true, ['user.deactivate'], 'principal'],
        [$workspaceId, $workspaceId, $userId, false, true, ['user.deactivate'], 'principal'],
        [$workspaceId, $workspaceId, $userId, true, false, ['user.deactivate'], 'principal'],
        [$workspaceId, $workspaceId, $userId, true, true, ['user.create'], 'principal'],
    ] as $arguments) {
        expect(fn () => $service->execute(...$arguments))->toThrow(AuthorizationException::class);
    }

    expect(DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->where('user_id', $userId)->exists())
        ->toBeTrue();
    expect(DB::table('audit_events')->where('workspace_id', $workspaceId)->count())->toBe(0);
});

it('keeps membership when the required audit write fails', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $user = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    app(WorkspaceRoleManager::class)->addMember($user, (string) $workspace->getKey());

    // Simulate an audit storage outage after the membership deletion begins.
    $events = Mockery::mock(AuditEventRepository::class);
    $events->shouldReceive('store')->once()->andThrow(new RuntimeException('audit unavailable'));
    $this->app->instance(AuditEventRepository::class, $events);

    $workspaceId = (string) $workspace->getKey();
    $userId = (string) $user->getKey();
    expect(fn () => app(DeactivateWorkspaceMember::class)->execute(
        $workspaceId, $workspaceId, $userId, true, true, ['user.deactivate'], 'principal',
    ))->toThrow(RuntimeException::class, 'audit unavailable');

    expect(DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->where('user_id', $userId)->exists())
        ->toBeTrue();
});

it('rejects an existing authenticated session at the next tenant request after deactivation', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $user = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    app(WorkspaceRoleManager::class)->addMember($user, (string) $workspace->getKey());
    $workspaceId = (string) $workspace->getKey();
    $userId = (string) $user->getKey();

    $this->actingAs($user);
    app(DeactivateWorkspaceMember::class)->execute(
        $workspaceId, $workspaceId, $userId, true, true, ['user.deactivate'], 'principal',
    );

    $this->getJson('/workspaces/'.$workspaceId.'/audit/export')->assertForbidden();
});
