<?php

use App\Modules\Consent\Application\Unsubscribe\AcceptOneClickUnsubscribe;
use App\Modules\Consent\Domain\Suppression\SuppressionAuthorityType;
use App\Modules\Consent\Domain\Suppression\SuppressionRecord;
use App\Modules\Consent\Domain\Unsubscribe\OpaqueUnsubscribeToken;
use App\Modules\Consent\Domain\Unsubscribe\UnsubscribeScope;
use App\Modules\Consent\Infrastructure\Suppression\DatabaseSuppressionRepository;
use App\Modules\Contacts\Application\CreateContact;
use App\Modules\DeliveryEngine\Application\Eligibility\EvaluateDeliveryEligibility;
use App\Modules\DeliveryEngine\Application\SuppressionSync\SuppressionSynchronizationOutcome;
use App\Modules\DeliveryEngine\Application\SuppressionSync\SuppressionSynchronizationRequest;
use App\Modules\DeliveryEngine\Application\SuppressionSync\SynchronizeSuppression;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityContext;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Eligibility\PolicyBasisType;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Providers\Domain\Feedback\EvaluateProviderFeedback;
use App\Modules\Providers\Domain\Feedback\ProviderFeedbackClassification;
use App\Modules\Providers\Domain\Feedback\ProviderFeedbackDisposition;
use App\Modules\Providers\Domain\Feedback\ProviderFeedbackEvidence;
use App\Modules\Providers\Domain\Feedback\ProviderFeedbackType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function task0027SecurityWorkspace(string $suffix, ?string $organizationId = null): array
{
    $organizationId ??= (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $brandId = (string) Str::uuid();
    $now = now();

    if (! DB::table('organizations')->where('id', $organizationId)->exists()) {
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'name' => 'Task0027 Security Org '.$suffix,
            'slug' => 'task0027-security-org-'.$suffix,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Task0027 Security Workspace '.$suffix,
        'slug' => 'task0027-security-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('brands')->insert([
        'id' => $brandId,
        'workspace_id' => $workspaceId,
        'name' => 'Task0027 Security Brand '.$suffix,
        'slug' => 'task0027-security-brand-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'organization_id' => $organizationId,
        'workspace_id' => $workspaceId,
        'brand_id' => $brandId,
        'context' => new TenantContext(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            brandId: $brandId,
            actorId: 'task0027-security-'.$suffix,
        ),
    ];
}

function task0027SecurityEligibility(?string $jurisdiction = 'GB', bool $providerKnown = true): EligibilityContext
{
    return new EligibilityContext(
        messagePurpose: MessageIntentType::Marketing,
        jurisdiction: $jurisdiction,
        subscriberType: 'individual',
        solicitationBasis: 'direct_marketing',
        relationshipBasis: 'existing_customer',
        policyBasis: PolicyBasisType::ExplicitConsent,
        policyBasisEvidencePresent: true,
        jurisdictionPolicyOutcome: EligibilityOutcome::Allow,
        policyVersion: 'gb-pecr-2026-02',
        policyEffectiveAt: new DateTimeImmutable('2026-02-05T00:00:00+00:00'),
        providerKey: $providerKnown ? 'provider-a' : null,
        providerContextKnown: $providerKnown,
        suppressionApplies: false,
        objectionApplies: false,
    );
}

it('fails closed when suppression evidence crosses workspace boundaries', function () {
    $left = task0027SecurityWorkspace('left');
    $right = task0027SecurityWorkspace('right', $left['organization_id']);
    $contact = app(CreateContact::class)->handle($left['context'], firstName: 'Scoped');

    $foreignRecord = new SuppressionRecord(
        id: (string) Str::uuid(),
        workspaceId: $right['workspace_id'],
        contactId: $contact->id,
        channel: 'email',
        purpose: 'marketing',
        authorityType: SuppressionAuthorityType::Unsubscribe,
        sourceType: 'rfc8058_one_click',
        sourceVersion: 'RFC8058',
        providerKey: null,
        idempotencyKey: 'security-cross-workspace',
        observedAt: new DateTimeImmutable('2026-09-17T10:00:00+00:00'),
        effectiveAt: new DateTimeImmutable('2026-09-17T10:00:00+00:00'),
        freshUntil: null,
        immutableEvidence: ['token_digest' => hash('sha256', 'opaque')],
    );

    expect(fn () => app(DatabaseSuppressionRepository::class)->appendSuppression($foreignRecord))
        ->toThrow(AuthorizationException::class, 'Contact access denied');
});

it('keeps one-click token material opaque and replay identity deterministic', function () {
    $rawToken = str_repeat('s', 43);
    $token = new OpaqueUnsubscribeToken(
        value: $rawToken,
        scope: new UnsubscribeScope(
            workspaceId: 'workspace-1',
            contactId: 'contact-1',
            channel: 'email',
            purpose: 'marketing',
            scopeType: 'list',
            scopeKey: 'newsletter',
        ),
        issuedAt: new DateTimeImmutable('2026-09-17T09:00:00+00:00'),
    );
    $handler = new AcceptOneClickUnsubscribe;
    $at = new DateTimeImmutable('2026-09-17T10:00:00+00:00');

    $first = $handler->handle($token, 'POST', 'List-Unsubscribe=One-Click', $at);
    $replay = $handler->handle($token, 'POST', 'List-Unsubscribe=One-Click', $at);

    expect($first)->toEqual($replay)
        ->and($first->tokenDigest)->toBe(hash('sha256', $rawToken))
        ->and($first->idempotencyKey)->toBe('rfc8058:'.hash('sha256', $rawToken))
        ->and(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain($rawToken);
});

it('rejects secret and direct pii material from canonical suppression evidence', function () {
    $base = [
        'id' => (string) Str::uuid(),
        'workspaceId' => 'workspace-1',
        'contactId' => 'contact-1',
        'channel' => 'email',
        'purpose' => 'marketing',
        'authorityType' => SuppressionAuthorityType::Complaint,
        'sourceType' => 'provider_feedback',
        'sourceVersion' => 'v1',
        'providerKey' => 'provider-a',
        'idempotencyKey' => 'security-pii',
        'observedAt' => new DateTimeImmutable('2026-09-17T10:00:00+00:00'),
        'effectiveAt' => new DateTimeImmutable('2026-09-17T10:00:00+00:00'),
        'freshUntil' => null,
    ];

    expect(fn () => new SuppressionRecord(...$base, immutableEvidence: ['recipient_email' => 'person@example.com']))
        ->toThrow(InvalidArgumentException::class, 'secret or direct-PII')
        ->and(fn () => new SuppressionRecord(...$base, immutableEvidence: ['access_token' => 'secret-value']))
        ->toThrow(InvalidArgumentException::class, 'secret or direct-PII');
});

it('rejects untrusted stale and contradictory provider feedback', function () {
    $evaluator = new EvaluateProviderFeedback;
    $now = new DateTimeImmutable('2026-09-17T10:00:00+00:00');

    $untrusted = new ProviderFeedbackEvidence(
        id: 'feedback-untrusted',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        providerKey: 'provider-a',
        type: ProviderFeedbackType::Complaint,
        classification: ProviderFeedbackClassification::Complaint,
        replayKey: 'replay-untrusted',
        sourceVersion: 'v1',
        observedAt: new DateTimeImmutable('2026-09-17T09:00:00+00:00'),
        freshUntil: null,
        trustedSource: false,
        redactedEvidence: ['event_class' => 'complaint'],
    );
    $stale = new ProviderFeedbackEvidence(
        id: 'feedback-stale',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        providerKey: 'provider-a',
        type: ProviderFeedbackType::Complaint,
        classification: ProviderFeedbackClassification::Complaint,
        replayKey: 'replay-stale',
        sourceVersion: 'v1',
        observedAt: new DateTimeImmutable('2026-09-17T08:00:00+00:00'),
        freshUntil: new DateTimeImmutable('2026-09-17T09:00:00+00:00'),
        trustedSource: true,
        redactedEvidence: ['event_class' => 'complaint'],
    );

    expect($evaluator->evaluate($untrusted, $now))->toBe(ProviderFeedbackDisposition::Reject)
        ->and($evaluator->evaluate($stale, $now))->toBe(ProviderFeedbackDisposition::Reject)
        ->and(fn () => new ProviderFeedbackEvidence(
            id: 'feedback-contradiction',
            workspaceId: 'workspace-1',
            contactId: 'contact-1',
            providerKey: 'provider-a',
            type: ProviderFeedbackType::Complaint,
            classification: ProviderFeedbackClassification::PermanentBounce,
            replayKey: 'replay-contradiction',
            sourceVersion: 'v1',
            observedAt: $now,
            freshUntil: null,
            trustedSource: true,
            redactedEvidence: [],
        ))->toThrow(InvalidArgumentException::class, 'classification');
});

it('never lets provider reconciliation restore internally accepted suppression', function () {
    $handler = new SynchronizeSuppression;

    foreach (SuppressionSynchronizationOutcome::cases() as $outcome) {
        $result = $handler->handle(new SuppressionSynchronizationRequest(
            operationKey: 'security-sync-'.$outcome->value,
            workspaceId: 'workspace-1',
            suppressionRecordId: 'suppression-1',
            providerKey: 'provider-a',
            providerOutcome: $outcome,
            observedAt: new DateTimeImmutable('2026-09-17T10:00:00+00:00'),
        ));

        expect($result->internalSuppressionActive)->toBeTrue()
            ->and($result->restoresEligibility)->toBeFalse();
    }
});

it('fails closed when jurisdiction or provider context is unknown', function () {
    $evaluator = new EvaluateDeliveryEligibility;

    expect($evaluator->evaluate(task0027SecurityEligibility(jurisdiction: null)))
        ->toBe(EligibilityOutcome::Unknown)
        ->and($evaluator->evaluate(task0027SecurityEligibility(providerKnown: false)))
        ->toBe(EligibilityOutcome::Unknown);
});
