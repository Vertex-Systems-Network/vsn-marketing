<?php

use App\Modules\Identity\Application\Authorization\WorkspaceRoleManager;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Segmentation\Application\PreviewSegment;
use App\Modules\Segmentation\Application\SaveSegmentVersion;
use App\Modules\Segmentation\Domain\SegmentDefinitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{User,TenantContext} */
function task46Scope(string $label): array
{
    $actor = User::query()->create(['name' => $label, 'email' => $label.'@example.test', 'password' => 'test']);
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    DB::table('organizations')->insert(['id' => $organizationId, 'name' => $label, 'slug' => $label, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspaces')->insert(['id' => $workspaceId, 'organization_id' => $organizationId, 'name' => $label, 'slug' => $label, 'created_at' => now(), 'updated_at' => now()]);
    $roles = app(WorkspaceRoleManager::class);
    $member = $roles->addMember($actor, $workspaceId);
    $role = $roles->createRole($workspaceId, 'segment-editor', 'Segment editor');
    $roles->grantPermission($role, PermissionCatalog::CONTACT_READ);
    $roles->grantPermission($role, PermissionCatalog::CONTACT_WRITE);
    $roles->assignRole($member, $role);

    return [$actor, new TenantContext($organizationId, $workspaceId, null, (string) $actor->getKey())];
}

function task46Definition(string $domain): array
{
    return ['schema_version' => 1, 'subject' => 'contact', 'root' => [
        'type' => 'group', 'operator' => 'all', 'children' => [[
            'type' => 'attribute', 'field' => 'company.domain', 'operator' => 'equals', 'value' => $domain,
        ]],
    ]];
}

function task46MatchingContact(TenantContext $scope, string $domain): void
{
    $companyId = (string) Str::uuid();
    DB::table('companies')->insert(['id' => $companyId, 'workspace_id' => $scope->workspaceId, 'brand_id' => null,
        'name' => 'Example', 'domain' => $domain, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('contacts')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $scope->workspaceId,
        'brand_id' => null, 'company_id' => $companyId, 'first_name' => 'Private', 'last_name' => 'Name',
        'display_name' => 'Private Name', 'created_at' => now(), 'updated_at' => now()]);
}

it('bounds counts, withholds identities and rejects foreign pinned versions', function () {
    [$actor, $scope] = task46Scope('first');
    [$otherActor, $otherScope] = task46Scope('second');
    task46MatchingContact($scope, 'example.test');
    task46MatchingContact($scope, 'example.test');
    task46MatchingContact($otherScope, 'example.test');
    config()->set('segmentation.max_count_probe', 1);
    $definition = task46Definition('example.test');
    $version = app(SaveSegmentVersion::class)->create('Audience', $definition, $scope);
    $preview = app(PreviewSegment::class)->evaluate([], $scope, $actor, $version['id'], 1);
    expect($preview['count_kind'])->toBe('capped')
        ->and($preview['count_lower_bound'])->toBe(2)
        ->and($preview['preview_members'])->toBe([])
        ->and($preview['definition_hash'])->toBe($version['hash'])
        ->and($preview['source_freshness_at'])->toBeNull()
        ->and($preview['eligibility'])->toBe('not_evaluated');

    expect(fn () => app(PreviewSegment::class)->evaluate([], $otherScope, $otherActor, $version['id'], 1))
        ->toThrow(SegmentDefinitionException::class, 'segment_version_not_found');
    expect(fn () => app(PreviewSegment::class)->evaluate(task46Definition('changed.test'), $scope, $actor, $version['id'], 1))
        ->toThrow(SegmentDefinitionException::class, 'definition_version_mismatch');
});

it('appends immutable versions while publication remains pinned to its chosen version', function () {
    [, $scope] = task46Scope('versioned');
    $saved = app(SaveSegmentVersion::class)->create('Audience', task46Definition('one.test'), $scope);
    app(SaveSegmentVersion::class)->publish($saved['id'], 1, $scope);
    $next = app(SaveSegmentVersion::class)->revise($saved['id'], task46Definition('two.test'), $scope);
    $record = DB::table('segment_definitions')->where('id', $saved['id'])->first();
    expect($next['version'])->toBe(2)
        ->and($next['hash'])->not->toBe($saved['hash'])
        ->and($record->published_version_number)->toBe(1)
        ->and(DB::table('segment_definition_versions')->where('definition_id', $saved['id'])->count())->toBe(2);
});
