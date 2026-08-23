<?php

use App\Modules\Contacts\Application\AddContactToList;
use App\Modules\Contacts\Application\AssignTagToContact;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Application\CreateContactList;
use App\Modules\Contacts\Application\CreateTag;
use App\Modules\Contacts\Application\RemoveContactFromList;
use App\Modules\Contacts\Application\UnassignTagFromContact;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function contactGroupingTenantFixture(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'Grouping Organization '.$suffix,
        'slug' => 'grouping-organization-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Grouping Workspace '.$suffix,
        'slug' => 'grouping-workspace-'.$suffix,
    ]);
    $brand = Brand::query()->create([
        'workspace_id' => $workspace->getKey(),
        'name' => 'Grouping Brand '.$suffix,
        'slug' => 'grouping-brand-'.$suffix,
    ]);
    $otherBrand = Brand::query()->create([
        'workspace_id' => $workspace->getKey(),
        'name' => 'Grouping Other Brand '.$suffix,
        'slug' => 'grouping-other-brand-'.$suffix,
    ]);

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'brand' => $brand,
        'other_brand' => $otherBrand,
        'context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: (string) $brand->getKey(),
            actorId: 'grouping-'.$suffix,
        ),
        'other_brand_context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: (string) $otherBrand->getKey(),
            actorId: 'grouping-other-'.$suffix,
        ),
        'workspace_context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: null,
            actorId: 'grouping-workspace-'.$suffix,
        ),
    ];
}

it('persists canonical lists and tags with retry-safe auditable contact relationships', function () {
    $fixture = contactGroupingTenantFixture('lifecycle');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Grouped');
    $list = app(CreateContactList::class)->handle($fixture['context'], ' VIP Customers ');
    $tag = app(CreateTag::class)->handle($fixture['context'], ' Engaged ');

    expect(Str::isUuid($list->id))->toBeTrue()
        ->and(Str::isUuid($tag->id))->toBeTrue()
        ->and($list->workspaceId)->toBe($fixture['workspace']->getKey())
        ->and($tag->workspaceId)->toBe($fixture['workspace']->getKey())
        ->and($list->name)->toBe('VIP Customers')
        ->and($tag->name)->toBe('Engaged');

    expect(app(AddContactToList::class)->handle($fixture['context'], $list->id, $contact->id))->toBeTrue()
        ->and(app(AddContactToList::class)->handle($fixture['context'], $list->id, $contact->id))->toBeFalse()
        ->and(app(AssignTagToContact::class)->handle($fixture['context'], $tag->id, $contact->id))->toBeTrue()
        ->and(app(AssignTagToContact::class)->handle($fixture['context'], $tag->id, $contact->id))->toBeFalse();

    expect(DB::table('contact_list_memberships')->count())->toBe(1)
        ->and(DB::table('contact_tag_assignments')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', AddContactToList::AUDIT_ACTION)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', AssignTagToContact::AUDIT_ACTION)->count())->toBe(1);

    expect(app(RemoveContactFromList::class)->handle($fixture['context'], $list->id, $contact->id))->toBeTrue()
        ->and(app(RemoveContactFromList::class)->handle($fixture['context'], $list->id, $contact->id))->toBeFalse()
        ->and(app(UnassignTagFromContact::class)->handle($fixture['context'], $tag->id, $contact->id))->toBeTrue()
        ->and(app(UnassignTagFromContact::class)->handle($fixture['context'], $tag->id, $contact->id))->toBeFalse();

    expect(DB::table('contact_list_memberships')->count())->toBe(0)
        ->and(DB::table('contact_tag_assignments')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', RemoveContactFromList::AUDIT_ACTION)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', UnassignTagFromContact::AUDIT_ACTION)->count())->toBe(1);
});

it('fails closed when list tag or contact references cross workspace boundaries', function () {
    $primary = contactGroupingTenantFixture('scope-a');
    $outside = contactGroupingTenantFixture('scope-b');

    $primaryContact = app(CreateContact::class)->handle($primary['context'], firstName: 'Primary');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');
    $primaryList = app(CreateContactList::class)->handle($primary['context'], 'Primary List');
    $outsideList = app(CreateContactList::class)->handle($outside['context'], 'Outside List');
    $primaryTag = app(CreateTag::class)->handle($primary['context'], 'Primary Tag');
    $outsideTag = app(CreateTag::class)->handle($outside['context'], 'Outside Tag');

    expect(fn () => app(AddContactToList::class)->handle($primary['context'], $primaryList->id, $outsideContact->id))
        ->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(AddContactToList::class)->handle($primary['context'], $outsideList->id, $primaryContact->id))
        ->toThrow(AuthorizationException::class, 'Contact list access denied.');
    expect(fn () => app(AssignTagToContact::class)->handle($primary['context'], $primaryTag->id, $outsideContact->id))
        ->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(AssignTagToContact::class)->handle($primary['context'], $outsideTag->id, $primaryContact->id))
        ->toThrow(AuthorizationException::class, 'Tag access denied.');
});

it('preserves contact brand scope while keeping lists and tags workspace scoped', function () {
    $fixture = contactGroupingTenantFixture('brand-scope');
    $otherBrandContact = app(CreateContact::class)->handle($fixture['other_brand_context'], firstName: 'Other Brand');
    $list = app(CreateContactList::class)->handle($fixture['context'], 'Workspace List');
    $tag = app(CreateTag::class)->handle($fixture['context'], 'Workspace Tag');

    expect(fn () => app(AddContactToList::class)->handle($fixture['context'], $list->id, $otherBrandContact->id))
        ->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(AssignTagToContact::class)->handle($fixture['context'], $tag->id, $otherBrandContact->id))
        ->toThrow(AuthorizationException::class, 'Contact access denied.');

    expect(app(AddContactToList::class)->handle($fixture['workspace_context'], $list->id, $otherBrandContact->id))->toBeTrue()
        ->and(app(AssignTagToContact::class)->handle($fixture['workspace_context'], $tag->id, $otherBrandContact->id))->toBeTrue();
});

it('rejects empty canonical list and tag names', function () {
    $fixture = contactGroupingTenantFixture('names');

    expect(fn () => app(CreateContactList::class)->handle($fixture['context'], '   '))
        ->toThrow(InvalidArgumentException::class, 'Contact list name is required.');
    expect(fn () => app(CreateTag::class)->handle($fixture['context'], '   '))
        ->toThrow(InvalidArgumentException::class, 'Tag name is required.');
});
