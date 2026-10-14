<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Governance\ConfigureBrandAppearance;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists only safe brand appearance with active in-workspace logo and an audit revision', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $first = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $other = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $brand = Brand::query()->create(['workspace_id' => $first->getKey(), 'name' => 'Main', 'slug' => 'main']);
    $otherBrand = Brand::query()->create(['workspace_id' => $other->getKey(), 'name' => 'Other', 'slug' => 'other']);
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $first->getKey());
    $role = $roles->createRole((string) $first->getKey(), 'brand-admin', 'Brand admin');
    $roles->grantPermission($role, PermissionCatalog::BRAND_MANAGE);
    $roles->assignRole($membership, $role);
    $logo = (string) Str::uuid();
    $foreignLogo = (string) Str::uuid();
    foreach ([[(string) $first->getKey(), $logo], [(string) $other->getKey(), $foreignLogo]] as [$workspaceId, $assetId]) {
        DB::table('assets')->insert([
            'id' => $assetId, 'workspace_id' => $workspaceId, 'name' => 'Logo', 'kind' => 'image',
            'lifecycle' => 'active', 'created_by_actor_id' => (string) $user->getKey(),
            'audit_provenance' => '{}', 'created_at' => now(),
        ]);
    }

    $context = new TenantContext((string) $organization->getKey(), (string) $first->getKey(), null, (string) $user->getKey());
    $configure = app(ConfigureBrandAppearance::class);
    $safe = ['display_name' => 'Client Brand', 'accent_color' => '#225588', 'logo_asset_id' => $logo];
    expect(fn () => $configure->apply($user, $context, (string) $otherBrand->getKey(), $safe))->toThrow(AuthorizationException::class)
        ->and(fn () => $configure->apply($user, $context, (string) $brand->getKey(), [...$safe, 'logo_asset_id' => $foreignLogo]))->toThrow(AuthorizationException::class)
        ->and(fn () => $configure->apply($user, $context, (string) $brand->getKey(), [...$safe, 'accent_color' => '#FFFFFF']))->toThrow(AuthorizationException::class)
        ->and(fn () => $configure->apply($user, $context, (string) $brand->getKey(), [...$safe, 'origin' => 'https://spoof.test']))->toThrow(AuthorizationException::class);

    expect($configure->apply($user, $context, (string) $brand->getKey(), $safe))->toBe(1)
        ->and($configure->apply($user, $context, (string) $brand->getKey(), $safe))->toBe(2)
        ->and(DB::table('brand_appearance_settings')->where('brand_id', $brand->getKey())->value('logo_asset_id'))->toBe($logo)
        ->and(DB::table('audit_events')->where('action', 'identity.brand.appearance_configured')->count())->toBe(2);
});
