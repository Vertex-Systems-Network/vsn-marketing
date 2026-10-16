<?php

use App\Modules\Identity\Application\Agency\DelegatedAdministration;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function agencyUser(string $name, string $workspaceId, array $permissions): User
{
    $user = User::query()->create(['name' => $name, 'email' => Str::lower($name).'@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, $workspaceId);
    if ($permissions !== []) {
        $role = $roles->createRole($workspaceId, 'role-'.Str::lower($name), $name);
        foreach ($permissions as $permission) {
            $roles->grantPermission($role, $permission);
        }
        $roles->assignRole($membership, $role);
    }

    return $user;
}

/** @return array<string, mixed> */
function agencyScenario(): array
{
    $make = function (string $slug): string {
        $organization = Organization::query()->create(['name' => ucfirst($slug).' Org', 'slug' => $slug]);

        return (string) Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => ucfirst($slug), 'slug' => $slug])->getKey();
    };
    $agency = $make('agency');
    $clientA = $make('client-a');
    $clientB = $make('client-b');
    $brand = (string) Str::uuid();
    DB::table('brands')->insert(['id' => $brand, 'workspace_id' => $clientA, 'name' => 'A brand', 'slug' => 'a-brand',
        'created_at' => now(), 'updated_at' => now()]);

    return [
        'agency' => $agency, 'clientA' => $clientA, 'clientB' => $clientB, 'brand' => $brand,
        'agencyAdmin' => agencyUser('AgencyAdmin', $agency, [PermissionCatalog::AGENCY_MANAGE]),
        'agencyStaff' => agencyUser('AgencyStaff', $agency, [PermissionCatalog::CAMPAIGN_READ]),
        'clientAdmin' => agencyUser('ClientAdmin', $clientA, [PermissionCatalog::AGENCY_MANAGE]),
        'clientBUser' => agencyUser('ClientBUser', $clientB, [PermissionCatalog::CAMPAIGN_READ, PermissionCatalog::TEMPLATE_PUBLISH]),
    ];
}

it('inherits only delegated permissions for the linked client and isolates sibling clients', function (): void {
    $s = agencyScenario();
    $admin = app(DelegatedAdministration::class);
    expect($admin->allows($s['agencyAdmin'], $s['clientA'], PermissionCatalog::CAMPAIGN_READ))->toBeFalse();

    $admin->link($s['clientAdmin'], $s['clientA'], $s['agency'], [PermissionCatalog::CAMPAIGN_READ, PermissionCatalog::TEMPLATE_PUBLISH]);
    expect($admin->allows($s['agencyAdmin'], $s['clientA'], PermissionCatalog::CAMPAIGN_READ))->toBeTrue()
        ->and($admin->allows($s['agencyAdmin'], $s['clientA'], PermissionCatalog::CAMPAIGN_SEND))->toBeFalse()
        ->and($admin->allows($s['agencyAdmin'], $s['clientB'], PermissionCatalog::CAMPAIGN_READ))->toBeFalse()
        ->and($admin->allows($s['agencyStaff'], $s['clientA'], PermissionCatalog::CAMPAIGN_READ))->toBeFalse()
        ->and($admin->allows($s['clientBUser'], $s['clientA'], PermissionCatalog::CAMPAIGN_READ))->toBeFalse()
        ->and($admin->allows($s['clientAdmin'], $s['clientB'], PermissionCatalog::AGENCY_MANAGE))->toBeFalse();

    $admin->revoke($s['clientAdmin'], $s['clientA'], $s['agency']);
    expect($admin->allows($s['agencyAdmin'], $s['clientA'], PermissionCatalog::CAMPAIGN_READ))->toBeFalse();
    expect(DB::table('audit_events')->where('workspace_id', $s['clientA'])
        ->whereIn('action', ['agency.delegation.granted', 'agency.delegation.revoked'])->count())->toBe(2);
});

it('refuses privileged, unknown, self and unauthorized delegations', function (string $case): void {
    $s = agencyScenario();
    $admin = app(DelegatedAdministration::class);
    [$actor, $client, $agency, $permissions] = match ($case) {
        'billing' => [$s['clientAdmin'], $s['clientA'], $s['agency'], [PermissionCatalog::BILLING_MANAGE]],
        'identity' => [$s['clientAdmin'], $s['clientA'], $s['agency'], [PermissionCatalog::IDENTITY_MANAGE]],
        'credentials' => [$s['clientAdmin'], $s['clientA'], $s['agency'], [PermissionCatalog::PROVIDER_CREDENTIALS_MANAGE]],
        're-delegation' => [$s['clientAdmin'], $s['clientA'], $s['agency'], [PermissionCatalog::AGENCY_MANAGE]],
        'unknown' => [$s['clientAdmin'], $s['clientA'], $s['agency'], ['root.everything']],
        'self' => [$s['clientAdmin'], $s['clientA'], $s['clientA'], [PermissionCatalog::CAMPAIGN_READ]],
        'agency grants itself' => [$s['agencyAdmin'], $s['clientA'], $s['agency'], [PermissionCatalog::CAMPAIGN_READ]],
        'sibling grants for other' => [$s['clientBUser'], $s['clientA'], $s['agency'], [PermissionCatalog::CAMPAIGN_READ]],
    };
    expect(fn () => $admin->link($actor, $client, $agency, $permissions))->toThrow(AuthorizationException::class);
    expect(DB::table('agency_client_links')->count())->toBe(0);
})->with(['billing', 'identity', 'credentials', 're-delegation', 'unknown', 'self', 'agency grants itself', 'sibling grants for other']);

it('never lets privileged permissions be inherited even if stored in a tampered link', function (): void {
    $s = agencyScenario();
    DB::table('agency_client_links')->insert(['id' => (string) Str::uuid(), 'agency_workspace_id' => $s['agency'],
        'client_workspace_id' => $s['clientA'], 'delegated_permissions' => json_encode([PermissionCatalog::BILLING_MANAGE]),
        'approved_by' => $s['clientAdmin']->getKey(), 'created_at' => now(), 'updated_at' => now()]);
    expect(app(DelegatedAdministration::class)->allows($s['agencyAdmin'], $s['clientA'], PermissionCatalog::BILLING_MANAGE))->toBeFalse();
});

it('lets a delegated agency customize brand cosmetics while disclosures and origin stay system-owned', function (): void {
    $s = agencyScenario();
    $admin = app(DelegatedAdministration::class);
    $admin->link($s['clientAdmin'], $s['clientA'], $s['agency'], [PermissionCatalog::TEMPLATE_PUBLISH]);
    $view = $admin->customizeBrand($s['agencyAdmin'], $s['clientA'], $s['brand'],
        ['display_name' => 'Acme Coffee', 'primary_color' => '#AA3300', 'logo_url' => 'https://cdn.example.test/logo.png']);
    expect($view['display_name'])->toBe('Acme Coffee')->and($view['primary_color'])->toBe('#aa3300')
        ->and($view['security_origin'])->toBe(config('app.url'))
        ->and($view['disclosures']['ownership'])->toBe('Data controller: Client-a')
        ->and($view['disclosures'])->toHaveKeys(['consent', 'billing']);

    expect(fn () => $admin->customizeBrand($s['clientBUser'], $s['clientA'], $s['brand'],
        ['display_name' => 'Hijack', 'primary_color' => '#000000']))->toThrow(AuthorizationException::class);
    expect(fn () => $admin->customizeBrand($s['agencyAdmin'], $s['clientB'], $s['brand'],
        ['display_name' => 'Cross', 'primary_color' => '#000000']))->toThrow(AuthorizationException::class);
});

it('rejects brand input that spoofs security origin, consent, ownership or billing', function (array $input): void {
    $s = agencyScenario();
    $admin = app(DelegatedAdministration::class);
    $admin->link($s['clientAdmin'], $s['clientA'], $s['agency'], [PermissionCatalog::TEMPLATE_PUBLISH]);
    expect(fn () => $admin->customizeBrand($s['agencyAdmin'], $s['clientA'], $s['brand'], $input))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('brand_customizations')->count())->toBe(0);
})->with([
    'verified badge' => [['display_name' => 'Acme Verified', 'primary_color' => '#000000']],
    'sign-in lure' => [['display_name' => 'Acme Sign in', 'primary_color' => '#000000']],
    'domain spoof' => [['display_name' => 'login.acme.com', 'primary_color' => '#000000']],
    'consent claim' => [['display_name' => 'No unsubscribe needed', 'primary_color' => '#000000']],
    'billing claim' => [['display_name' => 'Acme Billing', 'primary_color' => '#000000']],
    'ownership claim' => [['display_name' => 'Operated by Acme', 'primary_color' => '#000000']],
    'markup' => [['display_name' => '<script>x</script>', 'primary_color' => '#000000']],
    'bad color' => [['display_name' => 'Acme', 'primary_color' => 'red;background:url(x)']],
    'javascript logo' => [['display_name' => 'Acme', 'primary_color' => '#000000', 'logo_url' => 'javascript:alert(1)']],
    'http logo' => [['display_name' => 'Acme', 'primary_color' => '#000000', 'logo_url' => 'http://cdn.example.test/l.png']],
    'extra field' => [['display_name' => 'Acme', 'primary_color' => '#000000', 'billing_text' => 'Free forever']],
]);
