<?php

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exports only the explicitly delegated client audit metadata, never sibling data or evidence', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $agency = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Agency', 'slug' => 'agency']);
    $client = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Client', 'slug' => 'client']);
    $sibling = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Sibling', 'slug' => 'sibling']);
    $user = User::query()->create(['name' => 'Delegate', 'email' => 'delegate@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $agency->getKey());
    $role = $roles->createRole((string) $agency->getKey(), 'auditor', 'Auditor');
    $roles->grantPermission($role, PermissionCatalog::AUDIT_EXPORT);
    $roles->assignRole($membership, $role);
    DB::table('agency_client_delegations')->insert([
        'id' => (string) Str::uuid(), 'agency_workspace_id' => $agency->getKey(),
        'client_workspace_id' => $client->getKey(), 'user_id' => $user->getKey(),
        'permission' => PermissionCatalog::AUDIT_EXPORT, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $clientEvent = app(AuditRecorder::class)->record((string) $client->getKey(), 'client.event', ['secret' => 'no-export']);
    app(AuditRecorder::class)->record((string) $sibling->getKey(), 'sibling.event', ['secret' => 'foreign']);
    $path = '/workspaces/'.$client->getKey().'/agency/'.$agency->getKey().'/audit/export';

    $this->getJson($path)->assertUnauthorized();
    $this->actingAs($user)->getJson($path)->assertOk()->assertJsonPath('items.0.id', $clientEvent->id)
        ->assertDontSee('no-export')->assertDontSee('sibling.event');
    $this->getJson('/workspaces/'.$sibling->getKey().'/agency/'.$agency->getKey().'/audit/export')->assertForbidden();
    $this->getJson('/workspaces/'.$client->getKey().'/audit/export')->assertForbidden();
});
