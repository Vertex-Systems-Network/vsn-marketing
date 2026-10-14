<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows only live memberships and currently granted module links', function (): void {
    $this->withoutVite();
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $own = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Own', 'slug' => 'own']);
    Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Foreign', 'slug' => 'foreign']);
    $user = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    $this->get('/workspaces')->assertRedirect('/auth/login');
    $this->getJson('/workspaces')->assertUnauthorized();
    $membership = app(WorkspaceRoleManager::class)->addMember($user, (string) $own->getKey());
    $this->actingAs($user)->get('/workspaces')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/workspaces')->has('workspaces', 1)
        ->where('workspaces.0.name', 'Own')->where('workspaces.0.links', []));

    $role = app(WorkspaceRoleManager::class)->createRole((string) $own->getKey(), 'analyst', 'Analyst');
    app(WorkspaceRoleManager::class)->grantPermission($role, PermissionCatalog::ANALYTICS_READ);
    app(WorkspaceRoleManager::class)->assignRole($membership, $role);
    $this->get('/workspaces')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('workspaces.0.links.0.url', route('analytics.operator', ['workspace' => $own->getKey()]))
        ->where('workspaces.0.links.0.name', 'Analytics'));

    DB::table('workspace_membership_roles')->where('workspace_membership_id', $membership)->delete();
    $this->get('/workspaces')->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspaces.0.links', []));
    DB::table('workspace_memberships')->where('id', $membership)->delete();
    $this->get('/workspaces')->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspaces', []));
});
