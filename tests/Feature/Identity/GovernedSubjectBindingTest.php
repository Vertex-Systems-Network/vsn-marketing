<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Federation\GovernedSubjectBinding;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('requires a separate current administrator and an existing member for an opaque IdP subject', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $other = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $actor = User::query()->create(['name' => 'One', 'email' => 'one@example.test', 'password' => 'unused']);
    $approver = User::query()->create(['name' => 'Two', 'email' => 'two@example.test', 'password' => 'unused']);
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
    $otherContext = new TenantContext((string) $organization->getKey(), (string) $workspace->getKey(), null, (string) $approver->getKey());
    config()->set('enterprise.oidc.workspace_id', (string) $workspace->getKey());
    config()->set('enterprise.oidc.issuer', 'https://example.auth0.test/');
    $binding = app(GovernedSubjectBinding::class);

    $id = $binding->propose($actor, $context, (string) $target->getKey(), 'auth0|opaque-subject');
    expect(fn () => $binding->approve($actor, $context, $id))->toThrow(AuthorizationException::class)
        ->and(fn () => $binding->apply($actor, $context, $id))->toThrow(AuthorizationException::class)
        ->and(fn () => $binding->approve($approver, new TenantContext((string) $organization->getKey(), (string) $other->getKey(), null, (string) $approver->getKey()), $id))->toThrow(AuthorizationException::class);
    $binding->approve($approver, $otherContext, $id);
    config()->set('enterprise.oidc.issuer', 'https://changed.example.test/');
    expect(fn () => $binding->apply($actor, $context, $id))->toThrow(AuthorizationException::class);
    config()->set('enterprise.oidc.issuer', 'https://example.auth0.test/');
    $binding->apply($actor, $context, $id);
    expect(fn () => $binding->apply($actor, $context, $id))->toThrow(AuthorizationException::class)
        ->and(DB::table('federation_subject_bindings')->where('subject', 'auth0|opaque-subject')->value('user_id'))->toBe((string) $target->getKey())
        ->and(DB::table('audit_events')->where('action', 'identity.federation.binding.applied')->count())->toBe(1);

    $second = $binding->propose($actor, $context, (string) $target->getKey(), 'auth0|another');
    $binding->approve($approver, $otherContext, $second);
    expect(fn () => $binding->apply($actor, $context, $second))->toThrow(AuthorizationException::class);

    $removed = $binding->propose($actor, $context, (string) $target->getKey(), 'auth0|removed');
    $binding->approve($approver, $otherContext, $removed);
    DB::table('workspace_memberships')->where('id', $targetMembership)->delete();
    expect(fn () => $binding->apply($actor, $context, $removed))->toThrow(AuthorizationException::class);

    $revoked = $binding->propose($actor, $context, (string) $approver->getKey(), 'auth0|revoked');
    $binding->approve($approver, $otherContext, $revoked);
    DB::table('workspace_memberships')->where('id', $approverMembership)->delete();
    expect(fn () => $binding->apply($actor, $context, $revoked))->toThrow(AuthorizationException::class);
});
