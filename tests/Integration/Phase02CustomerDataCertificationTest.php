<?php

use App\Modules\Consent\Application\GetEffectiveConsent;
use App\Modules\Consent\Application\RecordConsent;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Consent\Domain\EffectiveConsentStatus;
use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\AddContactToList;
use App\Modules\Contacts\Application\AssignTagToContact;
use App\Modules\Contacts\Application\CreateCompany;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Application\CreateContactList;
use App\Modules\Contacts\Application\CreateTag;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\Events\Application\GetContactTimeline;
use App\Modules\Events\Application\PersistCustomerEvent;
use App\Modules\Events\Domain\CanonicalEvent;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run PHASE-02 certification tests.');
    }
});

function phase02CertificationTenant(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'PHASE-02 Organization '.$suffix,
        'slug' => 'phase-02-organization-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'PHASE-02 Workspace '.$suffix,
        'slug' => 'phase-02-workspace-'.$suffix,
    ]);
    $brand = Brand::query()->create([
        'workspace_id' => $workspace->getKey(),
        'name' => 'PHASE-02 Brand '.$suffix,
        'slug' => 'phase-02-brand-'.$suffix,
    ]);

    return [
        'workspace' => $workspace,
        'brand' => $brand,
        'context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: (string) $brand->getKey(),
            actorId: 'phase-02-'.$suffix,
        ),
    ];
}

function phase02CertificationEvent(
    array $fixture,
    string $contactId,
    string $identityId,
    string $eventId,
    string $sourceEventId,
    array $payload,
): CanonicalEvent {
    return new CanonicalEvent(
        eventId: $eventId,
        eventType: 'contact.phase02_certified',
        occurredAt: new \DateTimeImmutable('2026-08-23T20:00:00+00:00'),
        receivedAt: new \DateTimeImmutable('2026-08-23T20:00:05+00:00'),
        workspaceId: (string) $fixture['workspace']->getKey(),
        brandId: (string) $fixture['brand']->getKey(),
        subjects: [
            'contact_id' => $contactId,
            'contact_identity_id' => $identityId,
        ],
        source: 'certification-provider',
        sourceEventId: $sourceEventId,
        schemaVersion: CanonicalEvent::SCHEMA_VERSION,
        payload: $payload,
        sourceMetadata: ['provider_event_name' => 'External Certification Event'],
    );
}

it('certifies the complete PHASE-02 customer data lifecycle and canonical invariants on PostgreSQL', function () {
    $fixture = phase02CertificationTenant('lifecycle');
    $context = $fixture['context'];

    $company = app(CreateCompany::class)->handle($context, 'Canonical Company', 'Example.COM.');
    $contact = app(CreateContact::class)->handle(
        $context,
        companyId: $company->id,
        firstName: 'Canonical',
        lastName: 'Customer',
    );
    $externalIdentity = app(AddContactIdentity::class)->handle(
        $context,
        $contact->id,
        ContactIdentityType::External,
        'provider-customer-42',
        provider: 'Certification-Provider',
        providerReference: 'provider-customer-42',
    );
    $list = app(CreateContactList::class)->handle($context, 'Certified Customers');
    $tag = app(CreateTag::class)->handle($context, 'Phase 02');

    expect(app(AddContactToList::class)->handle($context, $list->id, $contact->id))->toBeTrue()
        ->and(app(AssignTagToContact::class)->handle($context, $tag->id, $contact->id))->toBeTrue();

    $granted = app(RecordConsent::class)->handle(
        $context,
        $contact->id,
        'email',
        'marketing',
        'certification-form',
        ConsentDecision::Granted,
        new \DateTimeImmutable('2026-08-23T18:00:00+00:00'),
    );
    $denied = app(RecordConsent::class)->handle(
        $context,
        $contact->id,
        'email',
        'marketing',
        'preference-center',
        ConsentDecision::Denied,
        new \DateTimeImmutable('2026-08-23T19:00:00+00:00'),
    );
    $effective = app(GetEffectiveConsent::class)->handle($context, $contact->id, 'email', 'marketing');

    $eventId = (string) Str::uuid();
    $event = phase02CertificationEvent(
        $fixture,
        $contact->id,
        $externalIdentity->id,
        $eventId,
        'provider-event-42',
        ['revision' => 1],
    );
    $firstPersist = app(PersistCustomerEvent::class)->handle($context, $event);
    $retryPersist = app(PersistCustomerEvent::class)->handle($context, $event);
    $secondCanonicalEvent = phase02CertificationEvent(
        $fixture,
        $contact->id,
        $externalIdentity->id,
        (string) Str::uuid(),
        'provider-event-42',
        ['revision' => 2],
    );
    $secondPersist = app(PersistCustomerEvent::class)->handle($context, $secondCanonicalEvent);
    $timeline = app(GetContactTimeline::class)->handle($context, $contact->id);

    expect(Str::isUuid($company->id))->toBeTrue()
        ->and(Str::isUuid($contact->id))->toBeTrue()
        ->and(Str::isUuid($externalIdentity->id))->toBeTrue()
        ->and($externalIdentity->id)->not->toBe($externalIdentity->providerReference)
        ->and($externalIdentity->provider)->toBe('certification-provider')
        ->and($externalIdentity->providerReference)->toBe('provider-customer-42')
        ->and($externalIdentity->normalizedValue)->toBe('certification-provider:provider-customer-42')
        ->and($company->domain)->toBe('example.com')
        ->and($granted->id)->not->toBe($denied->id)
        ->and($effective->status)->toBe(EffectiveConsentStatus::Denied)
        ->and($effective->recordId)->toBe($denied->id)
        ->and($firstPersist->inserted)->toBeTrue()
        ->and($retryPersist->inserted)->toBeFalse()
        ->and($secondPersist->inserted)->toBeTrue()
        ->and($timeline)->toHaveCount(2)
        ->and(DB::table('companies')->where('id', $company->id)->count())->toBe(1)
        ->and(DB::table('contacts')->where('id', $contact->id)->value('company_id'))->toBe($company->id)
        ->and(DB::table('contact_identities')->where('id', $externalIdentity->id)->value('provider_reference'))->toBe('provider-customer-42')
        ->and(DB::table('contact_list_memberships')->where('contact_id', $contact->id)->count())->toBe(1)
        ->and(DB::table('contact_tag_assignments')->where('contact_id', $contact->id)->count())->toBe(1)
        ->and(DB::table('consent_records')->where('contact_id', $contact->id)->count())->toBe(2)
        ->and(DB::table('event_types')->where('canonical_name', $event->eventType)->count())->toBe(1)
        ->and(DB::table('customer_events')->where('source_event_id', 'provider-event-42')->count())->toBe(2);

    $conflictingRetry = phase02CertificationEvent(
        $fixture,
        $contact->id,
        $externalIdentity->id,
        $eventId,
        'provider-event-42',
        ['revision' => 999],
    );
    expect(fn () => app(PersistCustomerEvent::class)->handle($context, $conflictingRetry))
        ->toThrow(\InvalidArgumentException::class, 'Canonical event identity conflicts with persisted event data.');
});

it('certifies complete application-level cross-workspace fail-closed behavior across the PHASE-02 data core', function () {
    $primary = phase02CertificationTenant('scope-a');
    $outside = phase02CertificationTenant('scope-b');
    $primaryContext = $primary['context'];
    $outsideContext = $outside['context'];

    $outsideCompany = app(CreateCompany::class)->handle($outsideContext, 'Outside Company');
    $outsideContact = app(CreateContact::class)->handle(
        $outsideContext,
        companyId: $outsideCompany->id,
        firstName: 'Outside',
    );
    $outsideIdentity = app(AddContactIdentity::class)->handle(
        $outsideContext,
        $outsideContact->id,
        ContactIdentityType::External,
        'outside-provider-1',
        provider: 'outside-provider',
        providerReference: 'outside-provider-1',
    );
    $primaryContact = app(CreateContact::class)->handle($primaryContext, firstName: 'Primary');
    $primaryList = app(CreateContactList::class)->handle($primaryContext, 'Primary List');
    $primaryTag = app(CreateTag::class)->handle($primaryContext, 'Primary Tag');

    expect(fn () => app(CreateContact::class)->handle(
        $primaryContext,
        companyId: $outsideCompany->id,
        firstName: 'Invalid Company Reference',
    ))->toThrow(AuthorizationException::class, 'Company access denied.');

    expect(fn () => app(AddContactIdentity::class)->handle(
        $primaryContext,
        $outsideContact->id,
        ContactIdentityType::Email,
        'outside-through-primary@example.test',
    ))->toThrow(AuthorizationException::class, 'Contact access denied.');

    expect(fn () => app(AddContactToList::class)->handle($primaryContext, $primaryList->id, $outsideContact->id))
        ->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(AssignTagToContact::class)->handle($primaryContext, $primaryTag->id, $outsideContact->id))
        ->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(RecordConsent::class)->handle(
        $primaryContext,
        $outsideContact->id,
        'email',
        'marketing',
        'invalid-cross-workspace',
        ConsentDecision::Granted,
    ))->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(GetEffectiveConsent::class)->handle(
        $primaryContext,
        $outsideContact->id,
        'email',
        'marketing',
    ))->toThrow(AuthorizationException::class, 'Contact access denied.');

    $crossWorkspaceEvent = new CanonicalEvent(
        eventId: (string) Str::uuid(),
        eventType: 'contact.cross_workspace',
        occurredAt: new \DateTimeImmutable('2026-08-23T21:00:00+00:00'),
        receivedAt: new \DateTimeImmutable('2026-08-23T21:00:05+00:00'),
        workspaceId: (string) $primary['workspace']->getKey(),
        brandId: (string) $primary['brand']->getKey(),
        subjects: [
            'contact_id' => $outsideContact->id,
            'contact_identity_id' => $outsideIdentity->id,
        ],
        source: 'certification-provider',
        sourceEventId: 'cross-workspace-event',
        schemaVersion: CanonicalEvent::SCHEMA_VERSION,
        payload: [],
        sourceMetadata: [],
    );
    expect(fn () => app(PersistCustomerEvent::class)->handle($primaryContext, $crossWorkspaceEvent))
        ->toThrow(AuthorizationException::class, 'Customer event contact access denied.');
    expect(fn () => app(GetContactTimeline::class)->handle($primaryContext, $outsideContact->id))
        ->toThrow(AuthorizationException::class, 'Customer event contact access denied.');

    expect(DB::table('contacts')->where('workspace_id', $primary['workspace']->getKey())->count())->toBe(1)
        ->and(DB::table('contacts')->where('id', $primaryContact->id)->count())->toBe(1)
        ->and(DB::table('contact_identities')->where('workspace_id', $primary['workspace']->getKey())->count())->toBe(0)
        ->and(DB::table('consent_records')->where('workspace_id', $primary['workspace']->getKey())->count())->toBe(0)
        ->and(DB::table('customer_events')->where('workspace_id', $primary['workspace']->getKey())->count())->toBe(0);
});
