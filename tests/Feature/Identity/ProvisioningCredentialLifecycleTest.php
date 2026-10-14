<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('issues once, rotates atomically and revokes only a workspace-bound provisioning credential', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $one = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $two = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'unused']);
    $reader = User::query()->create(['name' => 'Reader', 'email' => 'reader@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $member = $roles->addMember($admin, (string) $one->getKey());
    $role = $roles->createRole((string) $one->getKey(), 'admin', 'Admin');
    $roles->grantPermission($role, PermissionCatalog::IDENTITY_MANAGE);
    $roles->assignRole($member, $role);
    $roles->addMember($reader, (string) $one->getKey());
    $path = '/workspaces/'.$one->getKey().'/identity/provisioning-credentials';
    $this->post($path)->assertRedirect('/auth/login');
    $this->actingAs($reader)->post($path)->assertForbidden();
    $this->actingAs($admin)->post('/workspaces/'.$two->getKey().'/identity/provisioning-credentials')->assertForbidden();

    $first = $this->post($path)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json();
    expect(strlen($first['token']))->toBe(64)
        ->and(DB::table('workspace_provisioning_credentials')->where('id', $first['id'])->value('secret_hash'))
        ->toBe(hash('sha256', $first['token']));
    $this->post($path)->assertForbidden();
    $this->post('/workspaces/'.$two->getKey().'/identity/provisioning-credentials/'.$first['id'].'/rotate')->assertForbidden();
    $second = $this->post($path.'/'.$first['id'].'/rotate')->assertCreated()->json();
    expect($second['token'])->not->toBe($first['token'])
        ->and(DB::table('workspace_provisioning_credentials')->where('id', $first['id'])->value('enabled'))->toBeFalsy()
        ->and(DB::table('workspace_provisioning_credentials')->where('id', $second['id'])->value('enabled'))->toBeTruthy();
    $this->post($path.'/'.$first['id'].'/rotate')->assertForbidden();
    $this->delete($path.'/'.$first['id'])->assertForbidden();
    $this->delete($path.'/'.$second['id'])->assertNoContent();
    expect(DB::table('workspace_provisioning_credentials')->where('id', $second['id'])->value('enabled'))->toBeFalsy()
        ->and(DB::table('audit_events')->where('workspace_id', $one->getKey())
            ->whereIn('action', ['identity.provisioning.credential_issued', 'identity.provisioning.credential_rotated', 'identity.provisioning.credential_revoked'])->count())->toBe(3);
    foreach (DB::table('audit_events')->where('workspace_id', $one->getKey())->pluck('evidence') as $evidence) {
        expect((string) $evidence)->not->toContain($first['token'])->not->toContain($second['token']);
    }
    expect(DB::table('workspace_provisioning_credentials')->where('workspace_id', $two->getKey())->count())->toBe(0);
});
