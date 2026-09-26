<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'sqlite'
    || DB::connection()->getDatabaseName() === ':memory:') {
    throw new RuntimeException('The E2E segment fixture requires testing mode and persistent SQLite.');
}

$organizationId = '00000000-0000-4000-8000-000000000046';
$workspaceId = '00000000-0000-4000-8000-000000000047';
$email = 'segment-operator-e2e@example.test';
$password = 'local-e2e-segment-password';
$user = User::query()->firstOrCreate(['email' => $email], [
    'name' => 'Segment E2E Operator', 'password' => Hash::make($password),
]);
DB::table('organizations')->insertOrIgnore(['id' => $organizationId, 'name' => 'E2E Organization',
    'slug' => 'segment-e2e-organization', 'created_at' => now(), 'updated_at' => now()]);
DB::table('workspaces')->insertOrIgnore(['id' => $workspaceId, 'organization_id' => $organizationId,
    'name' => 'Segment E2E', 'slug' => 'segment-e2e', 'created_at' => now(), 'updated_at' => now()]);
$roles = app(WorkspaceRoleManager::class);
$membership = $roles->addMember($user, $workspaceId);
$role = DB::table('workspace_roles')->where('workspace_id', $workspaceId)
    ->where('key', 'segment-e2e-editor')->value('id')
    ?? $roles->createRole($workspaceId, 'segment-e2e-editor', 'Segment E2E Editor');
$roles->grantPermission($role, PermissionCatalog::CONTACT_READ);
$roles->grantPermission($role, PermissionCatalog::CONTACT_WRITE);
$roles->assignRole($membership, $role);
$companyId = DB::table('companies')->where('workspace_id', $workspaceId)->where('domain', 'example.test')->value('id');
if ($companyId === null) {
    $companyId = (string) Str::uuid();
    DB::table('companies')->insert(['id' => $companyId, 'workspace_id' => $workspaceId, 'brand_id' => null,
        'name' => 'E2E Company', 'domain' => 'example.test', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('contacts')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
        'brand_id' => null, 'company_id' => $companyId,
        'first_name' => 'Hidden', 'last_name' => 'Contact', 'display_name' => 'Hidden Contact',
        'created_at' => now(), 'updated_at' => now()]);
}

echo json_encode(['workspace' => $workspaceId, 'email' => $email, 'password' => $password], JSON_THROW_ON_ERROR);
