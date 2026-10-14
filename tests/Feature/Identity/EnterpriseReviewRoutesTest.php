<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('binds separate role and billing approval receipts to two authenticated workspace operators', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $actor = User::query()->create(['name' => 'One', 'email' => 'one@example.test', 'password' => 'unused']);
    $approver = User::query()->create(['name' => 'Two', 'email' => 'two@example.test', 'password' => 'unused']);
    $target = User::query()->create(['name' => 'Target', 'email' => 'target@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $role = $roles->createRole((string) $workspace->getKey(), 'admin', 'Admin');
    foreach ([PermissionCatalog::IDENTITY_MANAGE, PermissionCatalog::BILLING_MANAGE] as $permission) {
        $roles->grantPermission($role, $permission);
    }
    foreach ([$actor, $approver] as $person) {
        $roles->assignRole($roles->addMember($person, (string) $workspace->getKey()), $role);
    }
    $targetMembership = $roles->addMember($target, (string) $workspace->getKey());
    $base = '/workspaces/'.$workspace->getKey();

    $this->postJson($base.'/identity/role-proposals', ['membership_id' => $targetMembership, 'role_id' => $role])
        ->assertUnauthorized();
    $this->actingAs($actor);
    $roleProposal = $this->postJson($base.'/identity/role-proposals', [
        'membership_id' => $targetMembership, 'role_id' => $role,
    ])->assertCreated()->json('proposal_id');
    $this->postJson($base.'/identity/role-proposals/'.$roleProposal.'/approve')->assertForbidden();
    $this->actingAs($approver)->postJson($base.'/identity/role-proposals/'.$roleProposal.'/approve')->assertNoContent();
    $this->actingAs($actor)->postJson($base.'/identity/role-proposals/'.$roleProposal.'/apply')->assertNoContent();

    $meterProposal = $this->postJson($base.'/billing/meter-proposals', [
        'meter' => 'offline_actions', 'limit_units' => 5, 'enabled' => true,
    ])->assertCreated()->json('proposal_id');
    $this->postJson($base.'/billing/meter-proposals/'.$meterProposal.'/approve')->assertForbidden();
    $this->actingAs($approver)->postJson($base.'/billing/meter-proposals/'.$meterProposal.'/approve')->assertNoContent();
    $this->actingAs($actor)->postJson($base.'/billing/meter-proposals/'.$meterProposal.'/apply')->assertNoContent();
    $this->postJson($base.'/billing/meter-proposals/'.$meterProposal.'/apply')->assertForbidden();

    expect(DB::table('workspace_membership_roles')->where('workspace_membership_id', $targetMembership)->count())->toBe(1)
        ->and(DB::table('workspace_meter_entitlements')->where('workspace_id', $workspace->getKey())->value('limit_units'))->toBe(5);
});
