<?php

use App\Modules\Contacts\Application\AddContactToList;
use App\Modules\Contacts\Application\AssignTagToContact;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Application\CreateContactList;
use App\Modules\Contacts\Application\CreateTag;
use App\Modules\Contacts\Application\RemoveContactFromList;
use App\Modules\Contacts\Application\UnassignTagFromContact;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run service-backed contact grouping tests.');
    }
});

function contactGroupingIntegrationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Grouping Integration '.$suffix,
        'slug' => 'grouping-integration-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Grouping Workspace '.$suffix,
        'slug' => 'grouping-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Grouping Brand '.$suffix,
        'slug' => 'grouping-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'grouping-integration-'.$suffix,
        ),
    ];
}

it('persists retry-safe list and tag lifecycle operations on PostgreSQL', function () {
    $fixture = contactGroupingIntegrationTenant('lifecycle');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Grouped');
    $list = app(CreateContactList::class)->handle($fixture['context'], 'Postgres List');
    $tag = app(CreateTag::class)->handle($fixture['context'], 'Postgres Tag');

    expect(app(AddContactToList::class)->handle($fixture['context'], $list->id, $contact->id))->toBeTrue()
        ->and(app(AddContactToList::class)->handle($fixture['context'], $list->id, $contact->id))->toBeFalse()
        ->and(app(AssignTagToContact::class)->handle($fixture['context'], $tag->id, $contact->id))->toBeTrue()
        ->and(app(AssignTagToContact::class)->handle($fixture['context'], $tag->id, $contact->id))->toBeFalse();

    expect(DB::table('contact_list_memberships')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(1)
        ->and(DB::table('contact_tag_assignments')->where('workspace_id', $fixture['workspace_id'])->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', AddContactToList::AUDIT_ACTION)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', AssignTagToContact::AUDIT_ACTION)->count())->toBe(1);

    expect(app(RemoveContactFromList::class)->handle($fixture['context'], $list->id, $contact->id))->toBeTrue()
        ->and(app(RemoveContactFromList::class)->handle($fixture['context'], $list->id, $contact->id))->toBeFalse()
        ->and(app(UnassignTagFromContact::class)->handle($fixture['context'], $tag->id, $contact->id))->toBeTrue()
        ->and(app(UnassignTagFromContact::class)->handle($fixture['context'], $tag->id, $contact->id))->toBeFalse();
});

it('rejects a list membership whose list crosses PostgreSQL workspace boundaries', function () {
    $primary = contactGroupingIntegrationTenant('list-fk-a');
    $outside = contactGroupingIntegrationTenant('list-fk-b');
    $primaryContact = app(CreateContact::class)->handle($primary['context'], firstName: 'Primary');
    $outsideList = app(CreateContactList::class)->handle($outside['context'], 'Outside List');

    expect(fn () => DB::table('contact_list_memberships')->insert([
        'workspace_id' => $primary['workspace_id'],
        'list_id' => $outsideList->id,
        'contact_id' => $primaryContact->id,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a tag assignment whose contact crosses PostgreSQL workspace boundaries', function () {
    $primary = contactGroupingIntegrationTenant('tag-fk-a');
    $outside = contactGroupingIntegrationTenant('tag-fk-b');
    $primaryTag = app(CreateTag::class)->handle($primary['context'], 'Primary Tag');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');

    expect(fn () => DB::table('contact_tag_assignments')->insert([
        'workspace_id' => $primary['workspace_id'],
        'tag_id' => $primaryTag->id,
        'contact_id' => $outsideContact->id,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});
