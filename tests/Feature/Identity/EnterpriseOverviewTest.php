<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows an honest workspace governance state to audit-authorized operators only', function (): void {
    $this->withoutVite();
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $user = User::query()->create(['name' => 'Auditor', 'email' => 'auditor@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $workspace->getKey());
    $path = '/workspaces/'.$workspace->getKey().'/enterprise';
    $this->getJson($path)->assertUnauthorized();
    $this->actingAs($user)->get($path)->assertForbidden();

    $role = $roles->createRole((string) $workspace->getKey(), 'auditor', 'Auditor');
    $roles->grantPermission($role, PermissionCatalog::AUDIT_EXPORT);
    $roles->assignRole($membership, $role);
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('enterprise/overview')
        ->where('workspace_id', (string) $workspace->getKey())
        ->where('capabilities.1.status', 'Not configured')
        ->where('capabilities.2.status', 'Disabled until integration approval')
        ->where('capabilities.4.status', 'Offline metering only; live charges unavailable')
        ->where('capabilities.5.status', 'Synthetic offline exercise only')
        ->where('audit_export_url', route('workspace.audit.export', ['workspace' => $workspace->getKey()])));
});
