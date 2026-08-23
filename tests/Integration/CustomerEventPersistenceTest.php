<?php

use App\Modules\Contacts\Application\AddContactIdentity;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\Events\Application\GetContactTimeline;
use App\Modules\Events\Application\PersistCustomerEvent;
use App\Modules\Events\Domain\CanonicalEvent;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run service-backed customer event tests.');
    }
});

function customerEventIntegrationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Customer Event '.$suffix,
        'slug' => 'customer-event-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Event Workspace '.$suffix,
        'slug' => 'event-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Event Brand '.$suffix,
        'slug' => 'event-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'brand_id' => $brandId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'event-integration-'.$suffix,
        ),
    ];
}

function customerEventIntegrationEnvelope(
    array $fixture,
    string $contactId,
    string $eventId,
    ?string $contactIdentityId = null,
    ?string $sourceEventId = null,
    string $eventType = 'contact.activity',
): CanonicalEvent {
    $subjects = ['contact_id' => $contactId];
    if ($contactIdentityId !== null) {
        $subjects['contact_identity_id'] = $contactIdentityId;
    }

    return new CanonicalEvent(
        eventId: $eventId,
        eventType: $eventType,
        occurredAt: new \DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        receivedAt: new \DateTimeImmutable('2026-08-20T10:01:00+00:00'),
        workspaceId: $fixture['workspace_id'],
        brandId: $fixture['brand_id'],
        subjects: $subjects,
        source: 'integration-provider',
        sourceEventId: $sourceEventId,
        schemaVersion: CanonicalEvent::SCHEMA_VERSION,
        payload: ['durable' => true],
        sourceMetadata: ['provider_event_name' => 'provider-click'],
    );
}

it('durably persists canonical event types, subjects, audit evidence, and contact timelines on PostgreSQL', function () {
    $fixture = customerEventIntegrationTenant('lifecycle');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Postgres Event');
    $identity = app(AddContactIdentity::class)->handle(
        $fixture['context'], $contact->id, ContactIdentityType::Email, 'postgres-event@example.test',
    );
    $event = customerEventIntegrationEnvelope(
        $fixture, $contact->id, (string) Str::uuid(), $identity->id, 'external-1',
    );
    $result = app(PersistCustomerEvent::class)->handle($fixture['context'], $event);
    $timeline = app(GetContactTimeline::class)->handle($fixture['context'], $contact->id);

    expect($result->inserted)->toBeTrue()
        ->and(DB::table('customer_events')->where('id', $event->eventId)->value('workspace_id'))->toBe($fixture['workspace_id'])
        ->and(DB::table('customer_events')->where('id', $event->eventId)->value('contact_identity_id'))->toBe($identity->id)
        ->and(DB::table('event_types')->where('canonical_name', $event->eventType)->count())->toBe(1)
        ->and($timeline)->toHaveCount(1)
        ->and($timeline[0]->toArray())->toBe($event->toArray())
        ->and(DB::table('audit_events')->where('action', PersistCustomerEvent::AUDIT_ACTION)->count())->toBe(1);
});

it('keeps canonical event identity duplicate-safe without deduplicating provider references on PostgreSQL', function () {
    $fixture = customerEventIntegrationTenant('duplicates');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Duplicate');
    $first = customerEventIntegrationEnvelope(
        $fixture, $contact->id, (string) Str::uuid(), sourceEventId: 'same-provider-id',
    );
    $second = customerEventIntegrationEnvelope(
        $fixture, $contact->id, (string) Str::uuid(), sourceEventId: 'same-provider-id',
    );

    expect(app(PersistCustomerEvent::class)->handle($fixture['context'], $first)->inserted)->toBeTrue()
        ->and(app(PersistCustomerEvent::class)->handle($fixture['context'], $first)->inserted)->toBeFalse()
        ->and(app(PersistCustomerEvent::class)->handle($fixture['context'], $second)->inserted)->toBeTrue()
        ->and(DB::table('customer_events')->where('source_event_id', 'same-provider-id')->count())->toBe(2);
});

it('rejects direct cross-workspace customer event contact linkage on PostgreSQL', function () {
    $primary = customerEventIntegrationTenant('fk-a');
    $outside = customerEventIntegrationTenant('fk-b');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');
    $eventTypeId = (string) Str::uuid();
    $eventId = (string) Str::uuid();
    $now = now();

    DB::table('event_types')->insert([
        'id' => $eventTypeId,
        'workspace_id' => $primary['workspace_id'],
        'canonical_name' => 'contact.invalid',
        'schema_version' => CanonicalEvent::SCHEMA_VERSION,
        'created_at' => $now,
    ]);

    expect(fn () => DB::table('customer_events')->insert([
        'id' => $eventId,
        'workspace_id' => $primary['workspace_id'],
        'brand_id' => $primary['brand_id'],
        'event_type_id' => $eventTypeId,
        'contact_id' => $outsideContact->id,
        'contact_identity_id' => null,
        'occurred_at' => $now,
        'received_at' => $now,
        'source' => 'invalid',
        'source_event_id' => null,
        'schema_version' => CanonicalEvent::SCHEMA_VERSION,
        'subjects' => json_encode(['contact_id' => $outsideContact->id], JSON_THROW_ON_ERROR),
        'payload' => json_encode([], JSON_THROW_ON_ERROR),
        'source_metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]))->toThrow(QueryException::class);
});

it('rejects direct contact identity linkage to the wrong canonical contact on PostgreSQL', function () {
    $fixture = customerEventIntegrationTenant('identity-fk');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Primary');
    $other = app(CreateContact::class)->handle($fixture['context'], firstName: 'Other');
    $otherIdentity = app(AddContactIdentity::class)->handle(
        $fixture['context'], $other->id, ContactIdentityType::Email, 'other-identity@example.test',
    );
    $eventTypeId = (string) Str::uuid();
    $now = now();

    DB::table('event_types')->insert([
        'id' => $eventTypeId,
        'workspace_id' => $fixture['workspace_id'],
        'canonical_name' => 'contact.identity_invalid',
        'schema_version' => CanonicalEvent::SCHEMA_VERSION,
        'created_at' => $now,
    ]);

    expect(fn () => DB::table('customer_events')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $fixture['workspace_id'],
        'brand_id' => $fixture['brand_id'],
        'event_type_id' => $eventTypeId,
        'contact_id' => $contact->id,
        'contact_identity_id' => $otherIdentity->id,
        'occurred_at' => $now,
        'received_at' => $now,
        'source' => 'invalid',
        'source_event_id' => null,
        'schema_version' => CanonicalEvent::SCHEMA_VERSION,
        'subjects' => json_encode(['contact_id' => $contact->id, 'contact_identity_id' => $otherIdentity->id], JSON_THROW_ON_ERROR),
        'payload' => json_encode([], JSON_THROW_ON_ERROR),
        'source_metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $now,
    ]))->toThrow(QueryException::class);
});
