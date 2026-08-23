<?php

use App\Modules\Consent\Application\GetEffectiveConsent;
use App\Modules\Consent\Application\RecordConsent;
use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Consent\Domain\Contracts\ConsentRecordRepository;
use App\Modules\Consent\Domain\EffectiveConsentStatus;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\Identity\Domain\Tenancy\Brand;
use App\Modules\Identity\Domain\Tenancy\Organization;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Identity\Domain\Tenancy\Workspace;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function consentEvidenceTenantFixture(string $suffix): array
{
    $organization = Organization::query()->create([
        'name' => 'Consent Organization '.$suffix,
        'slug' => 'consent-organization-'.$suffix,
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Consent Workspace '.$suffix,
        'slug' => 'consent-workspace-'.$suffix,
    ]);
    $brand = Brand::query()->create([
        'workspace_id' => $workspace->getKey(),
        'name' => 'Consent Brand '.$suffix,
        'slug' => 'consent-brand-'.$suffix,
    ]);
    $otherBrand = Brand::query()->create([
        'workspace_id' => $workspace->getKey(),
        'name' => 'Consent Other Brand '.$suffix,
        'slug' => 'consent-other-brand-'.$suffix,
    ]);

    return [
        'workspace' => $workspace,
        'brand' => $brand,
        'context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: (string) $brand->getKey(),
            actorId: 'consent-'.$suffix,
        ),
        'other_brand_context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: (string) $otherBrand->getKey(),
            actorId: 'consent-other-'.$suffix,
        ),
        'workspace_context' => new TenantContext(
            organizationId: (string) $organization->getKey(),
            workspaceId: (string) $workspace->getKey(),
            brandId: null,
            actorId: 'consent-workspace-'.$suffix,
        ),
    ];
}

it('appends auditable consent evidence and resolves the latest decision deterministically', function () {
    $fixture = consentEvidenceTenantFixture('lifecycle');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Consenting');
    $first = app(RecordConsent::class)->handle(
        $fixture['context'],
        $contact->id,
        ' EMAIL ',
        ' MARKETING ',
        ' Signup-Form ',
        ConsentDecision::Granted,
        new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
    );
    $second = app(RecordConsent::class)->handle(
        $fixture['context'],
        $contact->id,
        'email',
        'marketing',
        'preference-center',
        ConsentDecision::Denied,
        new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
    );
    $effective = app(GetEffectiveConsent::class)->handle($fixture['context'], $contact->id, ' EMAIL ', ' MARKETING ');

    expect(Str::isUuid($first->id))->toBeTrue()
        ->and(Str::isUuid($second->id))->toBeTrue()
        ->and(DB::table('consent_records')->where('contact_id', $contact->id)->count())->toBe(2)
        ->and(DB::table('consent_records')->where('id', $first->id)->value('decision'))->toBe('granted')
        ->and(DB::table('consent_records')->where('id', $first->id)->value('source'))->toBe('signup-form')
        ->and($effective->status)->toBe(EffectiveConsentStatus::Denied)
        ->and($effective->recordId)->toBe($second->id)
        ->and($effective->isGranted())->toBeFalse()
        ->and(DB::table('audit_events')->where('action', RecordConsent::AUDIT_ACTION)->count())->toBe(2);
});

it('fails closed when effective consent is missing or conflicting at the latest occurrence', function () {
    $fixture = consentEvidenceTenantFixture('effective');
    $contact = app(CreateContact::class)->handle($fixture['context'], firstName: 'Effective');
    $query = app(GetEffectiveConsent::class);

    $missing = $query->handle($fixture['context'], $contact->id, 'email', 'marketing');
    expect($missing->status)->toBe(EffectiveConsentStatus::Missing)
        ->and($missing->isGranted())->toBeFalse();

    $occurredAt = new DateTimeImmutable('2026-08-22T12:00:00+00:00');
    app(RecordConsent::class)->handle(
        $fixture['context'], $contact->id, 'email', 'marketing', 'form-a', ConsentDecision::Granted, $occurredAt,
    );
    app(RecordConsent::class)->handle(
        $fixture['context'], $contact->id, 'email', 'marketing', 'form-b', ConsentDecision::Denied, $occurredAt,
    );

    $ambiguous = $query->handle($fixture['context'], $contact->id, 'email', 'marketing');
    expect($ambiguous->status)->toBe(EffectiveConsentStatus::Ambiguous)
        ->and($ambiguous->recordId)->toBeNull()
        ->and($ambiguous->isGranted())->toBeFalse();
});

it('fails closed across workspace and brand boundaries while workspace context may access its contact', function () {
    $primary = consentEvidenceTenantFixture('scope-a');
    $outside = consentEvidenceTenantFixture('scope-b');
    $outsideContact = app(CreateContact::class)->handle($outside['context'], firstName: 'Outside');
    $otherBrandContact = app(CreateContact::class)->handle($primary['other_brand_context'], firstName: 'Other Brand');

    expect(fn () => app(RecordConsent::class)->handle(
        $primary['context'], $outsideContact->id, 'email', 'marketing', 'form', ConsentDecision::Granted,
    ))->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(RecordConsent::class)->handle(
        $primary['context'], $otherBrandContact->id, 'email', 'marketing', 'form', ConsentDecision::Granted,
    ))->toThrow(AuthorizationException::class, 'Contact access denied.');
    expect(fn () => app(GetEffectiveConsent::class)->handle(
        $primary['context'], $outsideContact->id, 'email', 'marketing',
    ))->toThrow(AuthorizationException::class, 'Contact access denied.');

    $record = app(RecordConsent::class)->handle(
        $primary['workspace_context'],
        $otherBrandContact->id,
        'email',
        'marketing',
        'workspace-form',
        ConsentDecision::Granted,
    );
    expect($record->workspaceId)->toBe($primary['workspace']->getKey())
        ->and(DB::table('audit_events')->where('subject_id', $record->contactId)->value('brand_id'))
        ->toBe($primary['other_brand_context']->brandId);
});

it('exposes append-only consent persistence through the canonical repository contract', function () {
    $repository = app(ConsentRecordRepository::class);

    expect(method_exists($repository, 'append'))->toBeTrue()
        ->and(method_exists($repository, 'update'))->toBeFalse()
        ->and(method_exists($repository, 'delete'))->toBeFalse();
});
