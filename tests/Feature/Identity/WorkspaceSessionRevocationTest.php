<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Provisioning\DeactivateWorkspaceMember;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('revokes only the selected workspace for old sessions and permits a fresh login', function (): void {
    $this->withoutVite();
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $one = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $two = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $member = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => Hash::make('secret-pass')]);
    $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    foreach ([$one, $two] as $workspace) {
        $membership = $roles->addMember($member, (string) $workspace->getKey());
        $role = $roles->createRole((string) $workspace->getKey(), 'auditor', 'Auditor');
        $roles->grantPermission($role, PermissionCatalog::AUDIT_EXPORT);
        $roles->assignRole($membership, $role);
    }
    $adminMembership = $roles->addMember($admin, (string) $one->getKey());
    $adminRole = $roles->createRole((string) $one->getKey(), 'admin', 'Admin');
    $roles->grantPermission($adminRole, PermissionCatalog::IDENTITY_MANAGE);
    $roles->assignRole($adminMembership, $adminRole);
    $onePath = '/workspaces/'.$one->getKey().'/enterprise';
    $twoPath = '/workspaces/'.$two->getKey().'/enterprise';

    $this->post('/auth/login', ['email' => $member->email, 'password' => 'secret-pass'])->assertNoContent();
    $this->get($onePath)->assertOk();
    $this->get($twoPath)->assertOk();
    $generation = (int) session('identity_session_generation');
    $this->actingAs($admin)->post('/workspaces/'.$one->getKey().'/identity/members/'.$member->getKey().'/revoke-sessions')->assertNoContent();
    $this->actingAs($member)->get($onePath)->assertForbidden();
    $this->get($twoPath)->assertOk();
    $this->get('/workspaces')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->where('workspaces.0.reauth_required', true)->where('workspaces.0.links', [])
        ->where('workspaces.1.reauth_required', false));

    $this->post('/auth/logout')->assertNoContent();
    $this->post('/auth/login', ['email' => $member->email, 'password' => 'secret-pass'])->assertNoContent();
    expect((int) session('identity_session_generation'))->toBeGreaterThan($generation);
    $this->get($onePath)->assertOk();
    expect(DB::table('audit_events')->where('action', 'identity.session.workspace_revoked')->count())->toBe(1);
});

it('blocks an old session even when provisioning re-adds the same member', function (): void {
    $this->withoutVite();
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $member = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => Hash::make('secret-pass')]);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($member, (string) $workspace->getKey());
    $role = $roles->createRole((string) $workspace->getKey(), 'auditor', 'Auditor');
    $roles->grantPermission($role, PermissionCatalog::AUDIT_EXPORT);
    $roles->assignRole($membership, $role);
    $path = '/workspaces/'.$workspace->getKey().'/enterprise';
    $this->post('/auth/login', ['email' => $member->email, 'password' => 'secret-pass'])->assertNoContent();
    $this->get($path)->assertOk();
    app(DeactivateWorkspaceMember::class)->execute((string) $workspace->getKey(), (string) $workspace->getKey(),
        (string) $member->getKey(), true, true, ['user.deactivate'], 'credential-one');
    $this->get($path)->assertForbidden();
    $newMembership = $roles->addMember($member, (string) $workspace->getKey());
    $roles->assignRole($newMembership, $role);
    $this->get($path)->assertForbidden();
    $this->post('/auth/logout')->assertNoContent();
    $this->post('/auth/login', ['email' => $member->email, 'password' => 'secret-pass'])->assertNoContent();
    $this->get($path)->assertOk();
});
