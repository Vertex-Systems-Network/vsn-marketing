<?php

use App\Modules\Identity\Application\Authorization\GovernedRoleAssignment;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('requires least privilege and an independent administrator to delegate identity management', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $other = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $actor = User::query()->create(['name' => 'Admin One', 'email' => 'one@example.test', 'password' => 'unused']);
    $approver = User::query()->create(['name' => 'Admin Two', 'email' => 'two@example.test', 'password' => 'unused']);
    $target = User::query()->create(['name' => 'Target', 'email' => 'target@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $actorMembership = $roles->addMember($actor, (string) $workspace->getKey());
    $approverMembership = $roles->addMember($approver, (string) $workspace->getKey());
    $targetMembership = $roles->addMember($target, (string) $workspace->getKey());
    $admin = $roles->createRole((string) $workspace->getKey(), 'admin', 'Admin');
    $roles->grantPermission($admin, PermissionCatalog::IDENTITY_MANAGE);
    $roles->assignRole($actorMembership, $admin);
    $roles->assignRole($approverMembership, $admin);
    $context = new TenantContext((string) $organization->getKey(), (string) $workspace->getKey(), null, (string) $actor->getKey());
    $approverContext = new TenantContext((string) $organization->getKey(), (string) $workspace->getKey(), null, (string) $approver->getKey());
    $assign = app(GovernedRoleAssignment::class);

    $proposalId = $assign->propose($actor, $context, $targetMembership, $admin);
    expect(fn () => $assign->approve($actor, $context, $proposalId))->toThrow(AuthorizationException::class)
        ->and(fn () => $assign->apply($actor, $context, $proposalId))->toThrow(AuthorizationException::class);
    $foreignRole = $roles->createRole((string) $other->getKey(), 'foreign', 'Foreign');
    expect(fn () => $assign->propose($actor, $context, $targetMembership, $foreignRole))->toThrow(AuthorizationException::class);
    $billingRole = $roles->createRole((string) $workspace->getKey(), 'billing', 'Billing');
    $roles->grantPermission($billingRole, PermissionCatalog::BILLING_MANAGE);
    expect(fn () => $assign->propose($actor, $context, $targetMembership, $billingRole))->toThrow(AuthorizationException::class);
    expect(DB::table('workspace_membership_roles')->where('workspace_membership_id', $targetMembership)->count())->toBe(0);

    $assign->approve($approver, $approverContext, $proposalId);
    $assign->apply($actor, $context, $proposalId);
    expect(fn () => $assign->apply($actor, $context, $proposalId))->toThrow(AuthorizationException::class);
    expect(DB::table('workspace_membership_roles')->where('workspace_membership_id', $targetMembership)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('workspace_id', $workspace->getKey())->where('action', 'identity.role.assigned')->count())->toBe(1)
        ->and(DB::table('workspace_role_assignment_proposals')->where('id', $proposalId)->value('status'))->toBe('applied');

    $changed = $assign->propose($actor, $context, $targetMembership, $admin);
    $assign->approve($approver, $approverContext, $changed);
    $roles->grantPermission($admin, PermissionCatalog::BILLING_MANAGE);
    expect(fn () => $assign->apply($actor, $context, $changed))->toThrow(AuthorizationException::class)
        ->and(DB::table('workspace_role_assignment_proposals')->where('id', $changed)->value('status'))->toBe('approved');

    $fresh = $assign->propose($actor, $context, $targetMembership, $admin);
    $assign->approve($approver, $approverContext, $fresh);
    DB::table('workspace_memberships')->where('id', $approverMembership)->delete();
    expect(fn () => $assign->apply($actor, $context, $fresh))->toThrow(AuthorizationException::class)
        ->and(DB::table('workspace_role_assignment_proposals')->where('id', $fresh)->value('status'))->toBe('approved');
});
