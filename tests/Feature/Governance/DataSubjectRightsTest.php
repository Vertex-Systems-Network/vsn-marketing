<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Application\Governance\DataResidencyMap;
use App\Modules\Identity\Application\Governance\DataSubjectRights;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function governanceEuSurfaces(): void
{
    foreach (['database', 'queue', 'files', 'logs', 'backups', 'analytics', 'ai_egress', 'provider_egress'] as $surface) {
        config(['residency.surfaces.'.$surface => 'eu']);
    }
}

/** @return array{0: Workspace, 1: Workspace, 2: User, 3: string} */
function governanceScenario(): array
{
    governanceEuSurfaces();
    $organization = Organization::query()->create(['name' => 'Example', 'slug' => 'example']);
    $workspace = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'One', 'slug' => 'one']);
    $foreign = Workspace::query()->create(['organization_id' => $organization->getKey(), 'name' => 'Two', 'slug' => 'two']);
    $officer = User::query()->create(['name' => 'Officer', 'email' => 'officer@example.test', 'password' => 'unused']);
    $roles = app(WorkspaceRoleManager::class);
    $membership = $roles->addMember($officer, (string) $workspace->getKey());
    $role = $roles->createRole((string) $workspace->getKey(), 'privacy', 'Privacy officer');
    $roles->grantPermission($role, PermissionCatalog::PRIVACY_MANAGE);
    $roles->assignRole($membership, $role);

    return [$workspace, $foreign, $officer, governanceContact((string) $workspace->getKey())];
}

function governanceContact(string $workspaceId, string $occurredAt = '-1 day'): string
{
    $contact = (string) Str::uuid();
    DB::table('contacts')->insert(['id' => $contact, 'workspace_id' => $workspaceId, 'first_name' => 'Ada',
        'last_name' => 'Lovelace', 'display_name' => 'Ada L', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('contact_identities')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
        'contact_id' => $contact, 'type' => 'email', 'value' => 'ada-'.$contact.'@example.test',
        'normalized_value' => 'ada-'.$contact.'@example.test', 'created_at' => now(), 'updated_at' => now()]);
    $list = (string) Str::uuid();
    DB::table('contact_lists')->insert(['id' => $list, 'workspace_id' => $workspaceId, 'name' => 'List '.$contact,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('contact_list_memberships')->insert(['workspace_id' => $workspaceId, 'list_id' => $list,
        'contact_id' => $contact, 'created_at' => now()]);
    $type = DB::table('event_types')->where('workspace_id', $workspaceId)->value('id');
    if ($type === null) {
        $type = (string) Str::uuid();
        DB::table('event_types')->insert(['id' => $type, 'workspace_id' => $workspaceId,
            'canonical_name' => 'page.viewed', 'schema_version' => 1, 'created_at' => now()]);
    }
    DB::table('customer_events')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
        'event_type_id' => $type, 'contact_id' => $contact, 'occurred_at' => now()->modify($occurredAt),
        'received_at' => now(), 'source' => 'test', 'schema_version' => 1,
        'subjects' => json_encode(['email' => 'ada@example.test']), 'payload' => json_encode(['ip' => '203.0.113.9']),
        'source_metadata' => json_encode(['ua' => 'x']), 'created_at' => now()]);

    return $contact;
}

it('maps every data surface and fails closed on unset or cross-region destinations', function (): void {
    [$workspace] = governanceScenario();
    $map = app(DataResidencyMap::class);
    expect(array_keys($map->map((string) $workspace->getKey())))->toBe(['database', 'queue', 'files', 'logs',
        'backups', 'analytics', 'ai_egress', 'provider_egress']);
    expect(collect($map->map((string) $workspace->getKey()))->every(fn (array $s): bool => $s['admitted']))->toBeTrue();

    config(['residency.surfaces.ai_egress' => null, 'residency.surfaces.backups' => 'us']);
    $current = $map->map((string) $workspace->getKey());
    expect($current['ai_egress']['admitted'])->toBeFalse()->and($current['backups']['admitted'])->toBeFalse();
    expect(fn () => $map->assertRoutable((string) $workspace->getKey(), 'ai_egress'))->toThrow(AuthorizationException::class);
    expect(fn () => $map->assertRoutable((string) $workspace->getKey(), 'provider_egress', 'us-east-1'))->toThrow(AuthorizationException::class);
    expect(fn () => $map->assertRoutable((string) $workspace->getKey(), 'unknown_surface', 'eu'))->toThrow(AuthorizationException::class);

    DB::table('workspace_data_policies')->insert(['workspace_id' => $workspace->getKey(), 'region' => 'us',
        'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => $map->assertRoutable((string) $workspace->getKey(), 'database', 'us'))->toThrow(AuthorizationException::class);
});

it('exports only the workspace subject and refuses cross-region export targets', function (): void {
    [$workspace, $foreign, $officer, $contact] = governanceScenario();
    $rights = app(DataSubjectRights::class);
    $export = $rights->export($officer, (string) $workspace->getKey(), $contact);
    expect($export['contacts'])->toHaveCount(1)->and($export['customer_events'])->toHaveCount(1);
    expect(fn () => $rights->export($officer, (string) $foreign->getKey(), $contact))->toThrow(AuthorizationException::class);
    $foreignContact = governanceContact((string) $foreign->getKey());
    expect(fn () => $rights->export($officer, (string) $workspace->getKey(), $foreignContact))->toThrow(AuthorizationException::class);

    config(['residency.surfaces.files' => 'us']);
    expect(fn () => $rights->export($officer, (string) $workspace->getKey(), $contact))->toThrow(AuthorizationException::class);
    expect(DB::table('audit_events')->where('action', 'privacy.subject.exported')->count())->toBe(1);
});

it('denies privacy operations to members without the privacy permission', function (): void {
    [$workspace, , , $contact] = governanceScenario();
    $member = User::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'unused']);
    app(WorkspaceRoleManager::class)->addMember($member, (string) $workspace->getKey());
    $rights = app(DataSubjectRights::class);
    expect(fn () => $rights->erase($member, (string) $workspace->getKey(), $contact))->toThrow(AuthorizationException::class);
    expect(fn () => $rights->placeHold($member, (string) $workspace->getKey(), null, 'litigation'))->toThrow(AuthorizationException::class);
    expect(DB::table('contacts')->where('id', $contact)->value('first_name'))->toBe('Ada');
});

it('lets a legal hold take precedence over erasure until released, then anonymizes and reports retained evidence', function (): void {
    [$workspace, , $officer, $contact] = governanceScenario();
    $rights = app(DataSubjectRights::class);
    $workspaceId = (string) $workspace->getKey();
    $hold = $rights->placeHold($officer, $workspaceId, $contact, 'Pending litigation');

    expect($rights->erase($officer, $workspaceId, $contact)['status'])->toBe('blocked_by_legal_hold');
    expect(DB::table('contacts')->where('id', $contact)->value('first_name'))->toBe('Ada');

    $rights->releaseHold($officer, $workspaceId, $hold);
    expect(fn () => $rights->releaseHold($officer, $workspaceId, $hold))->toThrow(AuthorizationException::class);
    $result = $rights->erase($officer, $workspaceId, $contact);
    expect($result['status'])->toBe('completed')
        ->and($result['retained'])->toHaveKeys(['consent_records', 'suppression_records', 'delivery_recipient_snapshots']);
    expect(DB::table('contacts')->where('id', $contact)->value('first_name'))->toBeNull();
    expect(DB::table('contact_identities')->where('contact_id', $contact)->value('value'))->toStartWith('erased:');
    expect(DB::table('contact_list_memberships')->where('contact_id', $contact)->count())->toBe(0);
    expect((string) DB::table('customer_events')->where('contact_id', $contact)->value('payload'))->not->toContain('203.0.113.9');
    expect(DB::table('data_subject_requests')->where('contact_id', $contact)->pluck('status')->sort()->values()->all())
        ->toBe(['blocked_by_legal_hold', 'completed']);
    expect(DB::table('audit_events')->whereIn('action', ['privacy.legal_hold.placed', 'privacy.legal_hold.released',
        'privacy.subject.erasure_blocked', 'privacy.subject.erased'])->count())->toBe(4);
});

it('rolls back a partially failed erasure and records the failure', function (): void {
    [$workspace, , $officer, $contact] = governanceScenario();
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'delete from "contact_tag_assignments"')) {
            throw new RuntimeException('simulated storage failure');
        }
    });

    $result = app(DataSubjectRights::class)->erase($officer, (string) $workspace->getKey(), $contact);
    expect($result['status'])->toBe('failed_rolled_back');
    expect(DB::table('contacts')->where('id', $contact)->value('first_name'))->toBe('Ada');
    expect(DB::table('contact_identities')->where('contact_id', $contact)->value('value'))->not->toStartWith('erased:');
    expect(DB::table('contact_list_memberships')->where('contact_id', $contact)->count())->toBe(1);
    expect(DB::table('data_subject_requests')->where('status', 'failed_rolled_back')->count())->toBe(1);
});

it('applies event retention per workspace while honouring workspace and contact legal holds', function (): void {
    [$workspace, $foreign, $officer, $recent] = governanceScenario();
    $workspaceId = (string) $workspace->getKey();
    $old = governanceContact($workspaceId, '-400 days');
    $heldOld = governanceContact($workspaceId, '-400 days');
    $foreignOld = governanceContact((string) $foreign->getKey(), '-400 days');
    DB::table('workspace_data_policies')->insert(['workspace_id' => $workspaceId, 'region' => 'eu',
        'event_retention_days' => 365, 'created_at' => now(), 'updated_at' => now()]);
    $rights = app(DataSubjectRights::class);

    $workspaceHold = $rights->placeHold($officer, $workspaceId, null, 'Regulator inquiry');
    expect($rights->applyRetention($officer, $workspaceId))->toBe(0);
    $rights->releaseHold($officer, $workspaceId, $workspaceHold);

    $rights->placeHold($officer, $workspaceId, $heldOld, 'Contact dispute');
    expect($rights->applyRetention($officer, $workspaceId))->toBe(1);
    expect(DB::table('customer_events')->where('contact_id', $old)->count())->toBe(0);
    expect(DB::table('customer_events')->where('contact_id', $heldOld)->count())->toBe(1);
    expect(DB::table('customer_events')->where('contact_id', $recent)->count())->toBe(1);
    expect(DB::table('customer_events')->where('contact_id', $foreignOld)->count())->toBe(1);
});
