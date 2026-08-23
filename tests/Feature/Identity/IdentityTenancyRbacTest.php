<?php

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Tenancy\TenantContextResolver;
use App\Modules\Identity\Application\Tenancy\TenantContextStore;
use App\Modules\Identity\Application\Tenancy\UseTenantContext;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function identityFixture(): array
{
    $user = User::query()->create([
        'name' => 'Owner',
        'email' => 'owner@example.test',
        'password' => Hash::make('secret-pass'),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Vertex',
        'slug' => 'vertex',
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Marketing',
        'slug' => 'marketing',
    ]);

    $brand = Brand::query()->create([
        'workspace_id' => $workspace->getKey(),
        'name' => 'Primary',
        'slug' => 'primary',
    ]);

    app(WorkspaceRoleManager::class)->addMember($user, (string) $workspace->getKey());

    return compact('user', 'organization', 'workspace', 'brand');
}

it('authenticates with the first-party session guard and logs out cleanly', function () {
    $fixture = identityFixture();

    $this->post('/auth/login', [
        'email' => $fixture['user']->email,
        'password' => 'secret-pass',
    ])->assertNoContent();

    $this->assertAuthenticatedAs($fixture['user']);

    $this->post('/auth/logout')->assertNoContent();

    $this->assertGuest();
});

it('persists the organization workspace and brand hierarchy', function () {
    $fixture = identityFixture();

    expect($fixture['workspace']->organization_id)->toBe($fixture['organization']->getKey())
        ->and($fixture['brand']->workspace_id)->toBe($fixture['workspace']->getKey());
});

it('resolves tenant context only for an explicit workspace membership and matching brand', function () {
    $fixture = identityFixture();

    $context = app(TenantContextResolver::class)->resolve(
        $fixture['user'],
        (string) $fixture['workspace']->getKey(),
        (string) $fixture['brand']->getKey(),
    );

    expect($context->organizationId)->toBe($fixture['organization']->getKey())
        ->and($context->workspaceId)->toBe($fixture['workspace']->getKey())
        ->and($context->brandId)->toBe($fixture['brand']->getKey())
        ->and($context->actorId)->toBe($fixture['user']->getKey());

    $otherOrganization = Organization::query()->create(['name' => 'Other', 'slug' => 'other']);
    $otherWorkspace = Workspace::query()->create([
        'organization_id' => $otherOrganization->getKey(),
        'name' => 'Other Workspace',
        'slug' => 'other',
    ]);

    expect(fn () => app(TenantContextResolver::class)->resolve(
        $fixture['user'],
        (string) $otherWorkspace->getKey(),
    ))->toThrow(AuthorizationException::class, 'Workspace access denied.');
});

it('keeps canonical role permissions inside their workspace boundary', function () {
    $fixture = identityFixture();
    $roles = app(WorkspaceRoleManager::class);
    $membershipId = $roles->addMember($fixture['user'], (string) $fixture['workspace']->getKey());
    $roleId = $roles->createRole((string) $fixture['workspace']->getKey(), 'campaign-manager', 'Campaign Manager');

    $roles->grantPermission($roleId, PermissionCatalog::CAMPAIGN_SEND);
    $roles->assignRole($membershipId, $roleId);

    $context = app(TenantContextResolver::class)->resolve(
        $fixture['user'],
        (string) $fixture['workspace']->getKey(),
    );

    expect(app(WorkspaceAuthorizer::class)->allows(
        $fixture['user'],
        $context,
        PermissionCatalog::CAMPAIGN_SEND,
    ))->toBeTrue()
        ->and(app(WorkspaceAuthorizer::class)->allows(
            $fixture['user'],
            $context,
            'not.a.permission',
        ))->toBeFalse();

    Route::middleware(['web', 'auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CAMPAIGN_SEND])
        ->get('/_test/authorized/workspaces/{workspace}', fn () => response()->noContent());

    $this->actingAs($fixture['user'])
        ->get('/_test/authorized/workspaces/'.$fixture['workspace']->getKey())
        ->assertNoContent();

    $otherOrganization = Organization::query()->create(['name' => 'Second', 'slug' => 'second']);
    $otherWorkspace = Workspace::query()->create([
        'organization_id' => $otherOrganization->getKey(),
        'name' => 'Second',
        'slug' => 'second',
    ]);
    $otherMembership = $roles->addMember($fixture['user'], (string) $otherWorkspace->getKey());

    expect(fn () => $roles->assignRole($otherMembership, $roleId))
        ->toThrow(AuthorizationException::class, 'Role assignment must remain within one workspace.');
});

it('propagates the same tenant context through web api job and event boundaries', function () {
    $fixture = identityFixture();
    $workspaceId = (string) $fixture['workspace']->getKey();
    $brandId = (string) $fixture['brand']->getKey();

    Route::middleware(['web', 'auth', 'tenant'])->get(
        '/_test/web/workspaces/{workspace}/brands/{brand}',
        fn (Request $request) => response()->json($request->attributes->get('tenant_context')->toArray()),
    );

    Route::middleware(['api', 'auth', 'tenant'])->get(
        '/api/_test/workspaces/{workspace}/brands/{brand}',
        fn (Request $request) => response()->json($request->attributes->get('tenant_context')->toArray()),
    );

    $expected = [
        'organization_id' => (string) $fixture['organization']->getKey(),
        'workspace_id' => $workspaceId,
        'brand_id' => $brandId,
        'actor_id' => (string) $fixture['user']->getKey(),
    ];

    $this->actingAs($fixture['user'])
        ->getJson("/_test/web/workspaces/{$workspaceId}/brands/{$brandId}")
        ->assertOk()
        ->assertExactJson($expected);

    $this->actingAs($fixture['user'])
        ->getJson("/api/_test/workspaces/{$workspaceId}/brands/{$brandId}")
        ->assertOk()
        ->assertExactJson($expected);

    $context = TenantContext::fromArray($expected);
    $seen = null;

    (new UseTenantContext($context))->handle(new stdClass, function () use (&$seen): void {
        $seen = app(TenantContextStore::class)->require()->toArray();
    });

    expect($seen)->toBe($expected)
        ->and(app(TenantContextStore::class)->get())->toBeNull()
        ->and(TenantContext::fromEventMetadata($context->eventMetadata())->toArray())->toBe($expected);
});

it('fails closed for cross-workspace HTTP access and permission checks', function () {
    $fixture = identityFixture();
    $otherOrganization = Organization::query()->create(['name' => 'Outside', 'slug' => 'outside']);
    $otherWorkspace = Workspace::query()->create([
        'organization_id' => $otherOrganization->getKey(),
        'name' => 'Outside',
        'slug' => 'outside',
    ]);

    Route::middleware(['web', 'auth', 'tenant'])->get(
        '/_test/secure/workspaces/{workspace}',
        fn () => response()->noContent(),
    );

    $this->actingAs($fixture['user'])
        ->get("/_test/secure/workspaces/{$otherWorkspace->getKey()}")
        ->assertForbidden();

    $context = new TenantContext(
        organizationId: (string) $otherOrganization->getKey(),
        workspaceId: (string) $otherWorkspace->getKey(),
        brandId: null,
        actorId: (string) $fixture['user']->getKey(),
    );

    expect(app(WorkspaceAuthorizer::class)->allows(
        $fixture['user'],
        $context,
        PermissionCatalog::CAMPAIGN_SEND,
    ))->toBeFalse();
});
