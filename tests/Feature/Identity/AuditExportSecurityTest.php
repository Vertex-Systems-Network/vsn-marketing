<?php

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('exports only authorized workspace audit metadata with stable bounded pagination', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $first = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $second = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $user = User::query()->create(['name' => 'Reviewer', 'email' => 'reviewer@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($user, (string) $first->getKey());
    $roles->addMember($user, (string) $second->getKey());
    $role = $roles->createRole((string) $first->getKey(), 'auditor', 'Auditor');
    $roles->grantPermission($role, PermissionCatalog::AUDIT_EXPORT);
    $roles->assignRole($membership, $role);
    $audit = app(AuditRecorder::class);
    $older = $audit->record((string) $first->getKey(), 'test.older', ['secret' => 'never-export']);
    DB::table('audit_events')->where('id', $older->id)->update(['occurred_at' => now()->subDay()]);
    $newer = $audit->record((string) $first->getKey(), 'test.newer', ['secret' => 'never-export']);
    $foreign = $audit->record((string) $second->getKey(), 'test.foreign', ['secret' => 'foreign-secret']);
    $path = '/workspaces/'.$first->getKey().'/audit/export';

    $this->getJson($path)->assertUnauthorized();
    $this->actingAs($user)->getJson($path.'?limit=1')
        ->assertOk()->assertJsonCount(1, 'items')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('items.0.id', $newer->id)
        ->assertJsonPath('next_cursor', $newer->id)
        ->assertDontSee('never-export');
    $this->getJson($path.'?limit=1&before='.$newer->id)
        ->assertOk()->assertJsonPath('items.0.id', $older->id)
        ->assertJsonPath('next_cursor', null);
    $this->getJson($path.'?before='.$foreign->id)->assertNotFound();
    $this->getJson('/workspaces/'.$second->getKey().'/audit/export')->assertForbidden();
    $this->getJson($path.'?limit=101')->assertUnprocessable();
    expect(DB::table('audit_events')->where('workspace_id', $first->getKey())
        ->where('action', 'identity.audit.metadata_exported')->count())->toBe(2);
    $exportAudit = DB::table('audit_events')->where('workspace_id', $first->getKey())
        ->where('action', 'identity.audit.metadata_exported')->first();
    expect($exportAudit->actor_id)->toBe($user->getKey())
        ->and((string) $exportAudit->evidence)->not->toContain('never-export');
});
