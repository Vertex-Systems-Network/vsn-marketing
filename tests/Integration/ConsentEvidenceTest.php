<?php

use App\Modules\Consent\Application\GetEffectiveConsent;
use App\Modules\Consent\Application\RecordConsent;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Consent\Domain\EffectiveConsentStatus;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! filter_var(env('RUN_INFRA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_INFRA_INTEGRATION=true to run service-backed consent evidence tests.');
    }
});

function consentIntegrationTenant(string $suffix): array
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Consent Integration '.$suffix,
        'slug' => 'consent-integration-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Consent Workspace '.$suffix,
        'slug' => 'consent-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Consent Brand '.$suffix,
        'slug' => 'consent-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'workspace_id' => $workspaceId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'consent-integration-'.$suffix,
        ),
    ];
}

it('preserves append-only consent evidence and resolves effective consent on PostgreSQL', function () {
    $fixture = consentIntegrationTenant('lifecycle');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Postgres Consent');
    $first = app(RecordConsent::class)->handle(
        $fixture['context'],
        $contact->id,
        'email',
        'marketing',
        'signup-form',
        ConsentDecision::Granted,
        new \DateTimeImmutable('2026-08-20T10:00:00+00:00'),
    );
    app(RecordConsent::class)->handle(
        $fixture['context'],
        $contact->id,
        'email',
        'marketing',
        'preference-center',
        ConsentDecision::Denied,
        new \DateTimeImmutable('2026-08-21T10:00:00+00:00'),
    );
    $effective = app(GetEffectiveConsent::class)->handle($fixture['context'], $contact->id, 'email', 'marketing');

    expect(DB::table('consent_records')->where('contact_id', $contact->id)->count())->toBe(2)
        ->and(DB::table('consent_records')->where('id', $first->id)->value('decision'))->toBe('granted')
        ->and($effective->status)->toBe(EffectiveConsentStatus::Denied)
        ->and(DB::table('audit_events')->where('action', RecordConsent::AUDIT_ACTION)->count())->toBe(2);
});

it('rejects direct mutation of historical consent evidence on PostgreSQL', function () {
    $fixture = consentIntegrationTenant('append-only');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Immutable');
    $record = app(RecordConsent::class)->handle(
        $fixture['context'], $contact->id, 'email', 'marketing', 'form', ConsentDecision::Granted,
    );

    expect(fn () => DB::transaction(
        fn () => DB::table('consent_records')->where('id', $record->id)->update(['decision' => 'denied']),
    ))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(
        fn () => DB::table('consent_records')->where('id', $record->id)->delete(),
    ))->toThrow(QueryException::class);
    expect(DB::table('consent_records')->where('id', $record->id)->value('decision'))->toBe('granted');
});

it('fails closed for conflicting latest evidence on PostgreSQL', function () {
    $fixture = consentIntegrationTenant('ambiguous');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Ambiguous');
    $occurredAt = new \DateTimeImmutable('2026-08-22T12:00:00+00:00');

    app(RecordConsent::class)->handle(
        $fixture['context'], $contact->id, 'email', 'marketing', 'form-a', ConsentDecision::Granted, $occurredAt,
    );
    app(RecordConsent::class)->handle(
        $fixture['context'], $contact->id, 'email', 'marketing', 'form-b', ConsentDecision::Denied, $occurredAt,
    );

    $effective = app(GetEffectiveConsent::class)->handle($fixture['context'], $contact->id, 'email', 'marketing');
    expect($effective->status)->toBe(EffectiveConsentStatus::Ambiguous)
        ->and($effective->isGranted())->toBeFalse();
});

it('rejects a consent contact reference that crosses PostgreSQL workspace boundaries', function () {
    $primary = consentIntegrationTenant('fk-a');
    $outside = consentIntegrationTenant('fk-b');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');
    $now = now();

    expect(fn () => DB::table('consent_records')->insert([
        'id' => (string) Str::uuid(),
        'workspace_id' => $primary['workspace_id'],
        'contact_id' => $outsideContact->id,
        'channel' => 'email',
        'purpose' => 'marketing',
        'source' => 'invalid',
        'decision' => 'granted',
        'occurred_at' => $now,
        'created_at' => $now,
    ]))->toThrow(QueryException::class);
});
