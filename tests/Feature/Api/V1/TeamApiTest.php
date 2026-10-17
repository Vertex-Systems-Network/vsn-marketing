<?php

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Team\WorkspaceProvisioner;
use App\Modules\Identity\Domain\Authorization\DefaultRoles;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function teamSignUp(string $email = 'owner@example.test'): array
{
    $response = test()->postJson('/api/v1/auth/register', [
        'name' => 'Amelia Hart', 'email' => $email, 'password' => 'correct-horse-battery', 'workspace_name' => 'Northwind Co.',
    ])->assertCreated();

    return [$response->json('token'), $response->json('workspace_id'), User::query()->where('email', $email)->firstOrFail()];
}

function teamRoleId(string $workspaceId, string $key): string
{
    return (string) DB::table('workspace_roles')->where('workspace_id', $workspaceId)->where('key', $key)->value('id');
}

/** Adds a member with exactly the given role, bypassing invites, for authorization tests. */
function teamMember(string $workspaceId, string $roleKey, string $email): User
{
    $user = User::query()->create(['name' => Str::ucfirst($roleKey), 'email' => $email, 'password' => 'correct-horse-battery']);
    $roles = app(WorkspaceRoleManager::class);
    $roles->assignRole($roles->addMember($user, $workspaceId), teamRoleId($workspaceId, $roleKey));

    return $user;
}

it('AC-1 signup provisions the default roles and makes the founder Owner', function (): void {
    [$token, $workspace, $owner] = teamSignUp();

    $keys = DB::table('workspace_roles')->where('workspace_id', $workspace)->orderBy('key')->pluck('key')->all();
    expect($keys)->toBe(['admin', 'analyst', 'approver', 'marketer', 'owner', 'viewer']);

    $context = new TenantContext(organizationId: (string) DB::table('workspaces')->where('id', $workspace)->value('organization_id'), workspaceId: $workspace, brandId: null, actorId: (string) $owner->getKey());
    foreach (PermissionCatalog::all() as $permission) {
        expect(app(WorkspaceAuthorizer::class)->allows($owner, $context, $permission))->toBeTrue();
    }

    $this->withToken($token)->withHeader('X-Workspace-Id', $workspace)->getJson('/api/v1/members')
        ->assertOk()->assertJsonPath('data.0.roles', ['owner']);
    $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.is_platform_admin', false);
});

it('AC-1 Super Admin is a platform role granted only from the console', function (): void {
    [$token, , $owner] = teamSignUp();

    expect(Artisan::call('vsn:platform-admin', ['email' => $owner->email]))->toBe(0);
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.is_platform_admin', true);

    // No HTTP route can grant it.
    foreach (app('router')->getRoutes() as $route) {
        expect(str_contains((string) $route->uri(), 'platform-admin'))->toBeFalse();
    }
});

it('rejects weak passwords and duplicate emails at signup', function (): void {
    teamSignUp();
    $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'owner@example.test', 'password' => 'correct-horse-battery', 'workspace_name' => 'X'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
    $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'new@example.test', 'password' => 'short', 'workspace_name' => 'X'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
});

it('AC-2 invites a marketer who joins with exactly that role', function (): void {
    [$token, $workspace] = teamSignUp();
    $invite = $this->withToken($token)->withHeader('X-Workspace-Id', $workspace)
        ->postJson('/api/v1/invites', ['email' => 'Lea@Example.test', 'role_id' => teamRoleId($workspace, 'marketer')])
        ->assertCreated()->json('data');

    $this->withToken($token)->withHeader('X-Workspace-Id', $workspace)->getJson('/api/v1/invites')
        ->assertOk()->assertJsonPath('data.0.email', 'lea@example.test')->assertJsonPath('data.0.role', 'marketer');

    $lea = User::query()->create(['name' => 'Lea', 'email' => 'lea@example.test', 'password' => 'correct-horse-battery']);
    app('auth')->forgetGuards();
    $this->actingAs($lea, 'sanctum')->postJson('/api/v1/invites/'.$invite['token'].'/accept')
        ->assertOk()->assertJsonPath('data.workspace_id', $workspace);
    $this->actingAs($lea, 'sanctum')->withHeader('X-Workspace-Id', $workspace)->getJson('/api/v1/contacts')->assertOk();
    $this->actingAs($lea, 'sanctum')->withHeader('X-Workspace-Id', $workspace)->getJson('/api/v1/members')->assertForbidden();

    // One-time token.
    $this->actingAs($lea, 'sanctum')->postJson('/api/v1/invites/'.$invite['token'].'/accept')->assertForbidden();
    expect(DB::table('workspace_invites')->value('token_hash'))->toBe(hash('sha256', $invite['token']));
});

it('AC-3 adversarial: invites cannot carry privileged roles or permissions the inviter lacks', function (): void {
    [$token, $workspace] = teamSignUp();
    $this->withToken($token)->withHeader('X-Workspace-Id', $workspace);

    foreach (['owner', 'admin'] as $privileged) {
        $this->postJson('/api/v1/invites', ['email' => 'x@example.test', 'role_id' => teamRoleId($workspace, $privileged)])->assertForbidden();
    }

    // A custom admin-like role (identity.manage without billing) cannot escalate by creating a billing role.
    $admin = teamMember($workspace, 'admin', 'admin@example.test');
    app('auth')->forgetGuards();
    $this->actingAs($admin, 'sanctum')->withHeader('X-Workspace-Id', $workspace)
        ->postJson('/api/v1/roles', ['key' => 'payer', 'name' => 'Payer', 'permissions' => [PermissionCatalog::BILLING_MANAGE]])
        ->assertForbidden();
    $this->actingAs($admin, 'sanctum')->withHeader('X-Workspace-Id', $workspace)
        ->postJson('/api/v1/roles', ['key' => 'writer', 'name' => 'Writer', 'permissions' => [PermissionCatalog::CONTACT_READ, PermissionCatalog::AI_EXECUTE]])
        ->assertCreated();
    $this->actingAs($admin, 'sanctum')->withHeader('X-Workspace-Id', $workspace)
        ->postJson('/api/v1/roles', ['key' => 'owner', 'name' => 'Fake owner', 'permissions' => [PermissionCatalog::CONTACT_READ]])
        ->assertStatus(422);
    $this->actingAs($admin, 'sanctum')->withHeader('X-Workspace-Id', $workspace)
        ->postJson('/api/v1/roles', ['key' => 'odd', 'name' => 'Odd', 'permissions' => ['root.everything']])
        ->assertStatus(422);
});

it('AC-3 adversarial: non-administrators and foreign workspaces cannot manage the team', function (): void {
    [$token, $workspace] = teamSignUp();
    [$otherToken, $otherWorkspace] = teamSignUp('other@example.test');
    $marketer = teamMember($workspace, 'marketer', 'm@example.test');

    $this->actingAs($marketer, 'sanctum')->withHeader('X-Workspace-Id', $workspace)
        ->postJson('/api/v1/invites', ['email' => 'x@example.test', 'role_id' => teamRoleId($workspace, 'viewer')])->assertForbidden();
    $this->actingAs($marketer, 'sanctum')->withHeader('X-Workspace-Id', $workspace)
        ->postJson('/api/v1/roles', ['key' => 'r', 'name' => 'R', 'permissions' => [PermissionCatalog::CONTACT_READ]])->assertForbidden();

    app('auth')->forgetGuards();
    // Owner of workspace B cannot invite into workspace A, nor use A's role in B.
    $this->withToken($otherToken)->withHeader('X-Workspace-Id', $workspace)
        ->postJson('/api/v1/invites', ['email' => 'x@example.test', 'role_id' => teamRoleId($workspace, 'viewer')])->assertForbidden();
    $this->withToken($otherToken)->withHeader('X-Workspace-Id', $otherWorkspace)
        ->postJson('/api/v1/invites', ['email' => 'x@example.test', 'role_id' => teamRoleId($workspace, 'viewer')])->assertForbidden();
});

it('AC-3 adversarial: invites are bound to the invited email, expiry and revocation', function (): void {
    [$token, $workspace] = teamSignUp();
    $create = fn (string $email) => $this->withToken($token)->withHeader('X-Workspace-Id', $workspace)
        ->postJson('/api/v1/invites', ['email' => $email, 'role_id' => teamRoleId($workspace, 'viewer')])->json('data');

    $forLea = $create('lea@example.test');
    $mallory = User::query()->create(['name' => 'Mallory', 'email' => 'mallory@example.test', 'password' => 'correct-horse-battery']);
    app('auth')->forgetGuards();
    $this->actingAs($mallory, 'sanctum')->postJson('/api/v1/invites/'.$forLea['token'].'/accept')->assertForbidden();

    $lea = User::query()->create(['name' => 'Lea', 'email' => 'lea@example.test', 'password' => 'correct-horse-battery']);
    DB::table('workspace_invites')->where('id', $forLea['id'])->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($lea, 'sanctum')->postJson('/api/v1/invites/'.$forLea['token'].'/accept')->assertForbidden();

    app('auth')->forgetGuards();
    $forSam = $create('sam@example.test');
    $this->withToken($token)->withHeader('X-Workspace-Id', $workspace)->deleteJson('/api/v1/invites/'.$forSam['id'])->assertNoContent();
    $sam = User::query()->create(['name' => 'Sam', 'email' => 'sam@example.test', 'password' => 'correct-horse-battery']);
    $this->actingAs($sam, 'sanctum')->postJson('/api/v1/invites/'.$forSam['token'].'/accept')->assertForbidden();

    // A role that becomes privileged after inviting is refused at acceptance.
    app('auth')->forgetGuards();
    $forKim = $create('kim@example.test');
    DB::table('workspace_role_permissions')->insert([
        'workspace_role_id' => teamRoleId($workspace, 'viewer'), 'permission' => PermissionCatalog::IDENTITY_MANAGE,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $kim = User::query()->create(['name' => 'Kim', 'email' => 'kim@example.test', 'password' => 'correct-horse-battery']);
    $this->actingAs($kim, 'sanctum')->postJson('/api/v1/invites/'.$forKim['token'].'/accept')->assertForbidden();
});

it('default role definitions keep privileged permissions to owner and admin only', function (): void {
    foreach (DefaultRoles::all() as $key => $role) {
        expect(DefaultRoles::isPrivileged($role['permissions']))->toBe(in_array($key, ['owner', 'admin'], true), $key);
        expect(array_diff($role['permissions'], PermissionCatalog::all()))->toBe([]);
    }
    expect(app(WorkspaceProvisioner::class))->toBeInstanceOf(WorkspaceProvisioner::class);
});
