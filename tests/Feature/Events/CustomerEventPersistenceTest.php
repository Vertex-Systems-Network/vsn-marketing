<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\Events\Application\CanonicalEventRecorder;
use App\Modules\Events\Application\GetContactTimeline;
use App\Modules\Events\Application\PersistCustomerEvent;
use App\Modules\Events\Domain\CanonicalEvent;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

uses(RefreshDatabase::class);

function customerEventTenantFixture(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'Event Organization '.$suffix,
        'slug' => 'event-organization-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Event Workspace '.$suffix,
        'slug' => 'event-workspace-'.$suffix,
    ]);
    $brand = Brand::query()->create([
        'workspace_id' => $workspace->getKey(),
        'name' => 'Event Brand '.$suffix,
        'slug' => 'event-brand-'.$suffix,
    ]);

    return [
        'workspace' => $workspace,
        'brand' => $brand,
        'context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: (string) $brand->getKey(),
            actorId: 'event-'.$suffix,
        ),
    ];
}

function canonicalCustomerEvent(
    array $fixture,
    string $contactId,
    string $eventId,
    string $eventType,
    string $occurredAt,
    string $receivedAt,
    ?string $contactIdentityId = null,
    ?string $sourceEventId = null,
    array $payload = [],
): CanonicalEvent {
    $subjects = ['contact_id' => $contactId];
    if ($contactIdentityId !== null) {
        $subjects['contact_identity_id'] = $contactIdentityId;
    }

    return new CanonicalEvent(
        eventId: $eventId,
        eventType: $eventType,
        occurredAt: new DateTimeImmutable($occurredAt),
        receivedAt: new DateTimeImmutable($receivedAt),
        workspaceId: (string) $fixture['workspace']->getKey(),
        brandId: (string) $fixture['brand']->getKey(),
        subjects: $subjects,
        source: 'test-provider',
        sourceEventId: $sourceEventId,
        schemaVersion: CanonicalEvent::SCHEMA_VERSION,
        payload: $payload,
        sourceMetadata: ['provider_event_name' => 'External Event Name'],
    );
}

it('persists the TASK-0006 canonical envelope with event type, subject linkage, and audit evidence', function () {
    $fixture = customerEventTenantFixture('envelope');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Envelope');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'], $contact->id, ContactIdentityType::Email, 'event-envelope@example.test',
    );
    $event = app(CanonicalEventRecorder::class)->record(
        eventType: 'contact.activity',
        workspaceId: (string) $fixture['workspace']->getKey(),
        subjects: ['contact_id' => $contact->id, 'contact_identity_id' => $identity->id],
        aggregateType: 'contact',
        aggregateId: $contact->id,
        payload: ['action' => 'viewed'],
        brandId: (string) $fixture['brand']->getKey(),
        source: 'internal',
    );
    $result = app(PersistCustomerEvent::class)->handle($fixture['context'], $event);

    expect($result->inserted)->toBeTrue()
        ->and($result->event->toArray())->toBe($event->toArray())
        ->and($result->eventType->canonicalName)->toBe('contact.activity')
        ->and(DB::table('customer_events')->where('id', $event->eventId)->value('contact_id'))->toBe($contact->id)
        ->and(DB::table('customer_events')->where('id', $event->eventId)->value('contact_identity_id'))->toBe($identity->id)
        ->and(DB::table('event_types')->where('canonical_name', 'contact.activity')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', PersistCustomerEvent::AUDIT_ACTION)->count())->toBe(1);
});

it('is duplicate-safe by canonical event identity while provider references remain provenance only', function () {
    $fixture = customerEventTenantFixture('duplicates');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Duplicate');
    $eventId = (string) Str::uuid();
    $event = canonicalCustomerEvent(
        $fixture, $contact->id, $eventId, 'contact.clicked',
        '2026-08-20T10:00:00+00:00', '2026-08-20T10:01:00+00:00',
        sourceEventId: 'provider-42', payload: ['value' => 1],
    );

    $first = app(PersistCustomerEvent::class)->handle($fixture['context'], $event);
    $retry = app(PersistCustomerEvent::class)->handle($fixture['context'], $event);
    $secondCanonical = canonicalCustomerEvent(
        $fixture, $contact->id, (string) Str::uuid(), 'contact.clicked',
        '2026-08-20T11:00:00+00:00', '2026-08-20T11:01:00+00:00',
        sourceEventId: 'provider-42', payload: ['value' => 2],
    );
    $second = app(PersistCustomerEvent::class)->handle($fixture['context'], $secondCanonical);

    expect($first->inserted)->toBeTrue()
        ->and($retry->inserted)->toBeFalse()
        ->and($second->inserted)->toBeTrue()
        ->and(DB::table('customer_events')->count())->toBe(2)
        ->and(DB::table('audit_events')->where('action', PersistCustomerEvent::AUDIT_ACTION)->count())->toBe(2);

    $conflict = canonicalCustomerEvent(
        $fixture, $contact->id, $eventId, 'contact.clicked',
        '2026-08-20T10:00:00+00:00', '2026-08-20T10:01:00+00:00',
        sourceEventId: 'provider-42', payload: ['value' => 999],
    );
    expect(fn () => app(PersistCustomerEvent::class)->handle($fixture['context'], $conflict))
        ->toThrow(InvalidArgumentException::class, 'Canonical event identity conflicts with persisted event data.');
});

it('fails closed across workspace, brand, and contact identity subject boundaries', function () {
    $primary = customerEventTenantFixture('scope-a');
    $outside = customerEventTenantFixture('scope-b');
    $primaryContact = app(CreateContact::class)->handle($primary['context'], firstName: 'Primary');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');
    $outsideIdentity = app(AddContactIdentity::class)->handle(
        $outside['context'], $outsideContact->id, ContactIdentityType::Email, 'outside-event@example.test',
    );

    $outsideWorkspaceEvent = canonicalCustomerEvent(
        $outside, $outsideContact->id, (string) Str::uuid(), 'contact.activity',
        '2026-08-20T10:00:00+00:00', '2026-08-20T10:01:00+00:00',
    );
    expect(fn () => app(PersistCustomerEvent::class)->handle($primary['context'], $outsideWorkspaceEvent))
        ->toThrow(AuthorizationException::class, 'Customer event workspace access denied.');

    $wrongIdentity = canonicalCustomerEvent(
        $primary, $primaryContact->id, (string) Str::uuid(), 'contact.activity',
        '2026-08-20T10:00:00+00:00', '2026-08-20T10:01:00+00:00',
        contactIdentityId: $outsideIdentity->id,
    );
    expect(fn () => app(PersistCustomerEvent::class)->handle($primary['context'], $wrongIdentity))
        ->toThrow(AuthorizationException::class, 'Customer event identity access denied.');

    $wrongBrand = new CanonicalEvent(
        eventId: (string) Str::uuid(),
        eventType: 'contact.activity',
        occurredAt: new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        receivedAt: new DateTimeImmutable('2026-08-20T10:01:00+00:00'),
        workspaceId: (string) $primary['workspace']->getKey(),
        brandId: null,
        subjects: ['contact_id' => $primaryContact->id],
        source: 'internal',
        sourceEventId: null,
        schemaVersion: CanonicalEvent::SCHEMA_VERSION,
        payload: [],
        sourceMetadata: [],
    );
    expect(fn () => app(PersistCustomerEvent::class)->handle($primary['context'], $wrongBrand))
        ->toThrow(AuthorizationException::class, 'Customer event brand scope denied.');
});

it('returns a deterministic tenant-safe contact timeline ordered by occurred then received time', function () {
    $fixture = customerEventTenantFixture('timeline');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Timeline');
    $old = canonicalCustomerEvent(
        $fixture, $contact->id, (string) Str::uuid(), 'contact.old',
        '2026-08-19T10:00:00+00:00', '2026-08-19T10:01:00+00:00',
    );
    $sameOccurredEarlyReceive = canonicalCustomerEvent(
        $fixture, $contact->id, (string) Str::uuid(), 'contact.same_early',
        '2026-08-20T10:00:00+00:00', '2026-08-20T10:01:00+00:00',
    );
    $sameOccurredLateReceive = canonicalCustomerEvent(
        $fixture, $contact->id, (string) Str::uuid(), 'contact.same_late',
        '2026-08-20T10:00:00+00:00', '2026-08-20T10:02:00+00:00',
    );

    foreach ([$old, $sameOccurredEarlyReceive, $sameOccurredLateReceive] as $event) {
        app(PersistCustomerEvent::class)->handle($fixture['context'], $event);
    }

    $timeline = app(GetContactTimeline::class)->handle($fixture['context'], $contact->id);
    expect(array_map(fn (CanonicalEvent $event): string => $event->eventId, $timeline))->toBe([
        $sameOccurredLateReceive->eventId,
        $sameOccurredEarlyReceive->eventId,
        $old->eventId,
    ]);
});
