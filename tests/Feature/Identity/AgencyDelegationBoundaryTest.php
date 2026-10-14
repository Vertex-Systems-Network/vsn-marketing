<?php

use App\Modules\Identity\Application\Authorization\AgencyDelegationAuthorizer;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('limits delegation to one actor, client, permission and active agency role', function (): void {
    $agencyOrg = Organization::query()->create(['name' => 'Agency', 'slug' => 'agency']);
    $clientOrg = Organization::query()->create(['name' => 'Clients', 'slug' => 'clients']);
    $agency = Workspace::query()->create(['organization_id' => $agencyOrg->getKey(), 'name' => 'Agency', 'slug' => 'agency']);
    $client = Workspace::query()->create(['organization_id' => $clientOrg->getKey(), 'name' => 'Client', 'slug' => 'client']);
    $sibling = Workspace::query()->create(['organization_id' => $clientOrg->getKey(), 'name' => 'Sibling', 'slug' => 'sibling']);
    $user = User::query()->create(['name' => 'Delegate', 'email' => 'delegate@example.test', 'password' => 'unused']);
    $other = User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $agency->getKey());
    $role = $roles->createRole((string) $agency->getKey(), 'analyst', 'Analyst');
    $roles->grantPermission($role, PermissionCatalog::ANALYTICS_READ);
    $roles->assignRole($membership, $role);
    $grant = (string) Str::uuid();
    DB::table('agency_client_delegations')->insert([
        'id' => $grant, 'agency_workspace_id' => $agency->getKey(), 'client_workspace_id' => $client->getKey(),
        'user_id' => $user->getKey(), 'permission' => PermissionCatalog::ANALYTICS_READ,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $policy = app(AgencyDelegationAuthorizer::class);
    $context = new TenantContext((string) $clientOrg->getKey(), (string) $client->getKey(), null, (string) $user->getKey());
    $siblingContext = new TenantContext((string) $clientOrg->getKey(), (string) $sibling->getKey(), null, (string) $user->getKey());
    expect($policy->allows($user, (string) $agency->getKey(), $context, PermissionCatalog::ANALYTICS_READ))->toBeTrue()
        ->and($policy->allows($user, (string) $agency->getKey(), $siblingContext, PermissionCatalog::ANALYTICS_READ))->toBeFalse()
        ->and($policy->allows($user, (string) $agency->getKey(), $context, PermissionCatalog::BILLING_MANAGE))->toBeFalse()
        ->and($policy->allows($other, (string) $agency->getKey(), $context, PermissionCatalog::ANALYTICS_READ))->toBeFalse();

    DB::table('workspace_memberships')->where('id', $membership)->delete();
    expect($policy->allows($user, (string) $agency->getKey(), $context, PermissionCatalog::ANALYTICS_READ))->toBeFalse();
});
