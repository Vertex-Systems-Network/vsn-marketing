<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Governance\ContactDataInventory;
use App\Modules\Identity\Application\Governance\RecordContactDataRequest;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('records scoped review requests once and blocks held erasure and sibling contact access', function (): void {
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $first = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $second = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $operator = User::query()->create(['name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($operator, (string) $first->getKey());
    $role = $roles->createRole((string) $first->getKey(), 'privacy', 'Privacy');
    $roles->grantPermission($role, PermissionCatalog::CONTACT_READ);
    $roles->grantPermission($role, PermissionCatalog::CONTACT_WRITE);
    $roles->assignRole($membership, $role);
    $contactId = (string) Str::uuid();
    $otherContactId = (string) Str::uuid();
    foreach ([[(string) $first->getKey(), $contactId], [(string) $second->getKey(), $otherContactId]] as [$workspaceId, $id]) {
        DB::table('contacts')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'first_name' => 'Subject',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contact_identities')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'contact_id' => $id,
            'type' => 'email', 'value' => $id.'@example.test',
            'normalized_value' => $id.'@example.test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('contact_legal_holds')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $first->getKey(), 'contact_id' => $contactId,
        'issued_by' => $operator->getKey(), 'reason_fingerprint' => hash('sha256', 'legal-reference'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $context = new TenantContext((string) $organization->getKey(), (string) $first->getKey(), null, (string) $operator->getKey());
    $service = app(RecordContactDataRequest::class);
    expect(fn () => app(ContactDataInventory::class)->snapshot((string) $first->getKey(), $otherContactId))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $service->record($operator, $context, $contactId, 'erase', 'case-1', true))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->record($operator, $context, $otherContactId, 'export', 'case-2', true))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->record($operator, $context, $contactId, 'export', 'case-3', false))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->record($operator, $context, $contactId, 'unknown', 'case-4', true))->toThrow(AuthorizationException::class);

    $id = $service->record($operator, $context, $contactId, 'export', 'case-3', true);
    $snapshot = json_decode(DB::table('contact_data_requests')->where('id', $id)->value('inventory_snapshot'), true, flags: JSON_THROW_ON_ERROR);
    expect($service->record($operator, $context, $contactId, 'export', 'case-3', true))->toBe($id)
        ->and(DB::table('contact_data_requests')->where('workspace_id', $first->getKey())->value('status'))->toBe('pending_manual_review')
        ->and($snapshot['local_counts']['contacts'])->toBe(1)
        ->and($snapshot['local_counts']['contact_identities'])->toBe(1)
        ->and($snapshot['local_counts']['contact_legal_holds'])->toBe(1)
        ->and($snapshot['external_inventory_complete'])->toBeFalse()
        ->and(DB::table('audit_events')->where('action', 'privacy.contact_request.recorded')->count())->toBe(1);
});
