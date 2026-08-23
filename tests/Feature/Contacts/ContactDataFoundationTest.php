<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateCompany;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Application\UpdateCompany;
use App\Modules\Contacts\Application\UpdateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function contactDataTenantFixture(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'Organization '.$suffix,
        'slug' => 'organization-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Workspace '.$suffix,
        'slug' => 'workspace-'.$suffix,
    ]);
    $brand = Brand::query()->create([
        'workspace_id' => $workspace->getKey(),
        'name' => 'Brand '.$suffix,
        'slug' => 'brand-'.$suffix,
    ]);
    $otherBrand = Brand::query()->create([
        'workspace_id' => $workspace->getKey(),
        'name' => 'Other Brand '.$suffix,
        'slug' => 'other-brand-'.$suffix,
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
            actorId: 'actor-'.$suffix,
        ),
        'workspace_context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: null,
            actorId: 'actor-'.$suffix,
        ),
    ];
}

it('persists canonical contact company and normalized identity with non-PII audit evidence', function () {
    $fixture = contactDataTenantFixture('primary');

    $company = app(CreateCompany::class)->handle($fixture['context'], ' Acme Ltd ', 'Example.COM.');
    $contact = app(CreateContact::class)->handle(
        $fixture['context'],
        companyId: $company->id,
        firstName: ' Ada ',
        lastName: ' Lovelace ',
    );
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::Email,
        ' Ada@Example.COM ',
    );

    expect(Str::isUuid($company->id))->toBeTrue()
        ->and(Str::isUuid($contact->id))->toBeTrue()
        ->and($company->domain)->toBe('example.com')
        ->and($contact->companyId)->toBe($company->id)
        ->and($contact->firstName)->toBe('Ada')
        ->and($identity->normalizedValue)->toBe('ada@example.com');

    $storedIdentity = DB::table('contact_identities')->where('id', $identity->id)->first();
    expect($storedIdentity)->not->toBeNull()
        ->and($storedIdentity->workspace_id)->toBe($fixture['workspace']->getKey())
        ->and($storedIdentity->normalized_value)->toBe('ada@example.com');

    $identityAudit = DB::table('audit_events')->where('action', AddContactIdentity::AUDIT_ACTION)->first();
    $evidence = json_decode((string) $identityAudit->evidence, true, 512, JSON_THROW_ON_ERROR);
    expect($identityAudit->workspace_id)->toBe($fixture['workspace']->getKey())
        ->and($identityAudit->brand_id)->toBe($fixture['brand']->getKey())
        ->and($evidence)->toBe([
            'identity_id' => $identity->id,
            'type' => 'email',
            'provider' => null,
        ])
        ->and(json_encode($evidence, JSON_THROW_ON_ERROR))->not->toContain('ada@example.com');
});

it('fails closed for cross-workspace and cross-brand contact references', function () {
    $primary = contactDataTenantFixture('scope-a');
    $outside = contactDataTenantFixture('scope-b');

    $outsideCompany = app(CreateCompany::class)->handle($outside['context'], 'Outside Company');
    $primaryContact = app(CreateContact::class)->handle($primary['context'], firstName: 'Scoped');

    expect(fn () => app(UpdateContact::class)->handle(
        $outside['context'],
        $primaryContact->id,
        firstName: 'Denied',
    ))->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(CreateContact::class)->handle(
        $primary['context'],
        companyId: $outsideCompany->id,
        firstName: 'Denied',
    ))->toThrow(AuthorizationException::class, 'Company access denied.');

    $otherBrandContext = new TenantContext(
        organizationId: (string) $primary['organization']->getKey(),
        workspaceId: (string) $primary['workspace']->getKey(),
        brandId: (string) $primary['other_brand']->getKey(),
        actorId: 'actor-other-brand',
    );
    $otherBrandCompany = app(CreateCompany::class)->handle($otherBrandContext, 'Other Brand Company');

    expect(fn () => app(UpdateContact::class)->handle(
        $otherBrandContext,
        $primaryContact->id,
        firstName: 'Denied',
    ))->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(CreateContact::class)->handle(
        $primary['context'],
        companyId: $otherBrandCompany->id,
        firstName: 'Denied',
    ))->toThrow(AuthorizationException::class, 'Company brand scope denied.');
});

it('preserves brand scope when associating contacts and companies', function () {
    $fixture = contactDataTenantFixture('brand-rules');

    $workspaceCompany = app(CreateCompany::class)->handle($fixture['workspace_context'], 'Workspace Company');
    $brandCompany = app(CreateCompany::class)->handle($fixture['context'], 'Brand Company');

    $brandedContact = app(CreateContact::class)->handle(
        $fixture['context'],
        companyId: $workspaceCompany->id,
        firstName: 'Brand Scoped',
    );

    expect($brandedContact->companyId)->toBe($workspaceCompany->id);
    expect(fn () => app(CreateContact::class)->handle(
        $fixture['workspace_context'],
        companyId: $brandCompany->id,
        firstName: 'Workspace Scoped',
    ))->toThrow(AuthorizationException::class, 'Company brand scope denied.');
    expect(fn () => app(UpdateCompany::class)->handle(
        $fixture['context'],
        $workspaceCompany->id,
        'Denied Global Mutation',
    ))->toThrow(AuthorizationException::class, 'Company access denied.');
});

it('enforces workspace identity uniqueness while allowing the same normalized identity in another workspace', function () {
    $primary = contactDataTenantFixture('identity-a');
    $outside = contactDataTenantFixture('identity-b');

    $first = app(CreateContact::class)->handle($primary['context'], firstName: 'First');
    $second = app(CreateContact::class)->handle($primary['context'], firstName: 'Second');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');

    app(AddContactIdentity::class)->handle(
        $primary['context'],
        $first->id,
        ContactIdentityType::Email,
        'Unique@Example.COM',
    );

    expect(fn () => app(AddContactIdentity::class)->handle(
        $primary['context'],
        $second->id,
        ContactIdentityType::Email,
        'unique@example.com',
    ))->toThrow(InvalidArgumentException::class, 'Contact identity already exists in this workspace.');

    $outsideIdentity = app(AddContactIdentity::class)->handle(
        $outside['context'],
        $outsideContact->id,
        ContactIdentityType::Email,
        'unique@example.com',
    );
    expect($outsideIdentity->normalizedValue)->toBe('unique@example.com');
});

it('keeps external provider references secondary to canonical contact identity', function () {
    $fixture = contactDataTenantFixture('provider-ref');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Provider');

    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'],
        $contact->id,
        ContactIdentityType::External,
        'customer-42',
        provider: 'HubSpot',
        providerReference: 'customer-42',
    );

    expect(Str::isUuid($contact->id))->toBeTrue()
        ->and($contact->id)->not->toBe('customer-42')
        ->and($identity->provider)->toBe('hubspot')
        ->and($identity->providerReference)->toBe('customer-42')
        ->and($identity->normalizedValue)->toBe('hubspot:customer-42');
});
