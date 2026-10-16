<?php

use App\Modules\Audit\Application\AuditRecorder;
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
    throw new RuntimeException('The enterprise audit fixture requires testing mode and persistent SQLite.');
}

$fixtureId = Str::lower((string) Str::ulid());
$organizationId = (string) Str::uuid();
$workspaceId = (string) Str::uuid();
$foreignWorkspaceId = (string) Str::uuid();
$email = "enterprise-auditor-{$fixtureId}@example.test";
$password = 'local-e2e-enterprise-password';
$user = User::query()->create(['name' => 'Enterprise E2E Auditor', 'email' => $email, 'password' => Hash::make($password)]);
DB::table('organizations')->insert(['id' => $organizationId, 'name' => 'E2E Organization',
    'slug' => "enterprise-e2e-organization-{$fixtureId}", 'created_at' => now(), 'updated_at' => now()]);
foreach ([$workspaceId => 'auditor', $foreignWorkspaceId => 'foreign'] as $id => $name) {
    DB::table('workspaces')->insert(['id' => $id, 'organization_id' => $organizationId,
        'name' => "Enterprise E2E {$name}", 'slug' => "enterprise-e2e-{$name}-{$fixtureId}",
        'created_at' => now(), 'updated_at' => now()]);
}
$roles = app(WorkspaceRoleManager::class);
$membership = $roles->addMember($user, $workspaceId);
$role = $roles->createRole($workspaceId, 'enterprise-e2e-auditor', 'Enterprise E2E Auditor');
$roles->grantPermission($role, PermissionCatalog::AUDIT_EXPORT);
$roles->assignRole($membership, $role);
$audit = app(AuditRecorder::class);
for ($index = 0; $index < 26; $index++) {
    $audit->record($workspaceId, "e2e.audit.event.{$index}", ['secret' => 'never-show-evidence']);
}
$audit->record($foreignWorkspaceId, 'e2e.audit.foreign', ['secret' => 'foreign-evidence']);

echo json_encode(['workspace' => $workspaceId, 'foreign_workspace' => $foreignWorkspaceId,
    'email' => $email, 'password' => $password], JSON_THROW_ON_ERROR);
