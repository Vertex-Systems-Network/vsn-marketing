<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('writes only authorized in-workspace brand appearance through the operator route', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $first = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $second = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $brand = Brand::query()->create(['workspace_id' => $first->getKey(), 'name' => 'Main', 'slug' => 'main']);
    $foreign = Brand::query()->create(['workspace_id' => $second->getKey(), 'name' => 'Other', 'slug' => 'other']);
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $first->getKey());
    $role = $roles->createRole((string) $first->getKey(), 'brand', 'Brand');
    $path = '/workspaces/'.$first->getKey().'/brands/'.$brand->getKey().'/appearance';
    $foreignPath = '/workspaces/'.$first->getKey().'/brands/'.$foreign->getKey().'/appearance';
    $settings = ['display_name' => 'Client Brand', 'accent_color' => '#225588'];

    $this->postJson($path, $settings)->assertUnauthorized();
    $this->withoutVite();
    $this->getJson($path)->assertUnauthorized();
    $this->actingAs($user)->postJson($path, $settings)->assertForbidden();
    $this->get($path)->assertForbidden();
    $roles->grantPermission($role, PermissionCatalog::BRAND_MANAGE);
    $roles->assignRole($membership, $role);
    $this->postJson($path, [...$settings, 'origin' => 'https://spoof.test'])->assertUnprocessable();
    $this->postJson($foreignPath, $settings)->assertForbidden();
    $this->get($foreignPath)->assertForbidden();
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('display_name', 'Main')->where('logo_asset_id', null)->where('revision', 0));
    $this->postJson($path, $settings)->assertOk()->assertJsonPath('revision', 1);
    $this->postJson($path, [...$settings, 'logo_asset_id' => null])->assertOk()->assertJsonPath('revision', 2);
    $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('enterprise/brand-preview')
        ->where('display_name', 'Client Brand')
        ->where('accent_color', '#225588')
        ->where('logo_asset_id', null)
        ->where('revision', 2));
    expect(DB::table('brand_appearance_settings')->where('brand_id', $brand->getKey())->value('display_name'))->toBe('Client Brand');
});
