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
    throw new RuntimeException('The E2E journey fixture requires testing mode and persistent SQLite.');
}

$fixtureId = Str::lower((string) Str::ulid());
$organizationId = (string) Str::uuid();
$workspaceId = (string) Str::uuid();
$email = "journey-operator-{$fixtureId}@example.test";
$password = 'local-e2e-journey-password';
$user = User::query()->create([
    'name' => 'Journey E2E Operator', 'email' => $email, 'password' => Hash::make($password),
]);
DB::table('organizations')->insert([
    'id' => $organizationId, 'name' => 'E2E Journey Organization',
    'slug' => "journey-e2e-org-{$fixtureId}", 'created_at' => now(), 'updated_at' => now(),
]);
DB::table('workspaces')->insert([
    'id' => $workspaceId, 'organization_id' => $organizationId, 'name' => 'E2E Journey Workspace',
    'slug' => "journey-e2e-{$fixtureId}", 'created_at' => now(), 'updated_at' => now(),
]);
$roles = app(WorkspaceRoleManager::class);
$membership = $roles->addMember($user, $workspaceId);
$role = $roles->createRole($workspaceId, 'journey-e2e-editor', 'Journey E2E Editor');
foreach ([PermissionCatalog::JOURNEY_READ, PermissionCatalog::JOURNEY_CREATE, PermissionCatalog::JOURNEY_PUBLISH] as $permission) {
    $roles->grantPermission($role, $permission);
}
$roles->assignRole($membership, $role);

echo json_encode(['workspace' => $workspaceId, 'email' => $email, 'password' => $password], JSON_THROW_ON_ERROR);
