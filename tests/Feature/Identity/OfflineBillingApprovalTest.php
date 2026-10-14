<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Billing\ConfigureMeterEntitlement;
use App\Modules\Identity\Application\Billing\RecordMeteredUsage;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('requires two authorized workspace people and cannot cut below consumed usage', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $actor = User::query()->create(['name' => 'One', 'email' => 'one@example.test', 'password' => 'unused']);
    $approver = User::query()->create(['name' => 'Two', 'email' => 'two@example.test', 'password' => 'unused']);
    $outsider = User::query()->create(['name' => 'Three', 'email' => 'three@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $role = $roles->createRole((string) $workspace->getKey(), 'billing', 'Billing');
    $roles->grantPermission($role, PermissionCatalog::BILLING_MANAGE);
    $actorMembership = $roles->addMember($actor, (string) $workspace->getKey());
    $approverMembership = $roles->addMember($approver, (string) $workspace->getKey());
    $roles->assignRole($actorMembership, $role);
    $roles->assignRole($approverMembership, $role);

    $context = new TenantContext((string) $organization->getKey(), (string) $workspace->getKey(), null, (string) $actor->getKey());
    $approverContext = new TenantContext((string) $organization->getKey(), (string) $workspace->getKey(), null, (string) $approver->getKey());
    $outsiderContext = new TenantContext((string) $organization->getKey(), (string) $workspace->getKey(), null, (string) $outsider->getKey());
    $configure = app(ConfigureMeterEntitlement::class);
    $proposalId = $configure->propose($actor, $context, 'offline_actions', 5, true);
    expect(fn () => $configure->approve($actor, $context, $proposalId))->toThrow(AuthorizationException::class)
        ->and(fn () => $configure->approve($outsider, $outsiderContext, $proposalId))->toThrow(AuthorizationException::class)
        ->and(fn () => $configure->apply($actor, $context, $proposalId))->toThrow(AuthorizationException::class);
    $configure->approve($approver, $approverContext, $proposalId);
    $configure->apply($actor, $context, $proposalId);
    expect(fn () => $configure->apply($actor, $context, $proposalId))->toThrow(AuthorizationException::class);
    app(RecordMeteredUsage::class)->execute((string) $workspace->getKey(), 'offline_actions', 'event-1', 3, true);
    $lower = $configure->propose($actor, $context, 'offline_actions', 2, true);
    $configure->approve($approver, $approverContext, $lower);
    expect(fn () => $configure->apply($actor, $context, $lower))->toThrow(AuthorizationException::class);
    expect(DB::table('workspace_meter_entitlements')->where('workspace_id', $workspace->getKey())->value('limit_units'))->toBe(5)
        ->and(DB::table('audit_events')->where('action', 'billing.entitlement.configured')->count())->toBe(1)
        ->and(DB::table('workspace_meter_change_proposals')->where('id', $lower)->value('status'))->toBe('approved');

    $fresh = $configure->propose($actor, $context, 'offline_actions', 7, true);
    $configure->approve($approver, $approverContext, $fresh);
    DB::table('workspace_memberships')->where('id', $approverMembership)->delete();
    expect(fn () => $configure->apply($actor, $context, $fresh))->toThrow(AuthorizationException::class)
        ->and(DB::table('workspace_meter_change_proposals')->where('id', $fresh)->value('status'))->toBe('approved')
        ->and(DB::table('workspace_meter_entitlements')->where('workspace_id', $workspace->getKey())->value('limit_units'))->toBe(5);
});
