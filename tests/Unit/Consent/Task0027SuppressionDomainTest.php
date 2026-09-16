<?php

use App\Modules\Consent\Domain\Suppression\PreferenceDecision;
use App\Modules\Consent\Domain\Suppression\PreferenceRecord;
use App\Modules\Consent\Domain\Suppression\SuppressionAuthorityType;
use App\Modules\Consent\Domain\Suppression\SuppressionRecord;

it('derives deterministic suppression evidence fingerprints independent of key order', function () {
    $first = new SuppressionRecord(
        id: 'suppression-1',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        channel: 'email',
        purpose: 'marketing',
        authorityType: SuppressionAuthorityType::Unsubscribe,
        sourceType: 'rfc8058',
        sourceVersion: '8058',
        providerKey: null,
        idempotencyKey: 'unsubscribe-request-1',
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        effectiveAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        freshUntil: null,
        immutableEvidence: ['scope' => ['list' => 'weekly'], 'request' => 'accepted'],
    );
    $second = new SuppressionRecord(
        id: 'suppression-2',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        channel: 'email',
        purpose: 'marketing',
        authorityType: SuppressionAuthorityType::Unsubscribe,
        sourceType: 'rfc8058',
        sourceVersion: '8058',
        providerKey: null,
        idempotencyKey: 'unsubscribe-request-2',
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        effectiveAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        freshUntil: null,
        immutableEvidence: ['request' => 'accepted', 'scope' => ['list' => 'weekly']],
    );

    expect($first->evidenceFingerprint)->toBe($second->evidenceFingerprint);
});

it('rejects suppression evidence that becomes effective before it was observed', function () {
    expect(fn () => new SuppressionRecord(
        id: 'suppression-1',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        channel: 'email',
        purpose: 'marketing',
        authorityType: SuppressionAuthorityType::Objection,
        sourceType: 'operator',
        sourceVersion: null,
        providerKey: null,
        idempotencyKey: 'objection-1',
        observedAt: new DateTimeImmutable('2026-09-17T00:01:00+00:00'),
        effectiveAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        freshUntil: null,
        immutableEvidence: [],
    ))->toThrow(InvalidArgumentException::class, 'effectiveAt');
});

it('rejects secret and direct pii material from canonical suppression evidence', function () {
    expect(fn () => new SuppressionRecord(
        id: 'suppression-1',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        channel: 'email',
        purpose: 'marketing',
        authorityType: SuppressionAuthorityType::Complaint,
        sourceType: 'provider_webhook',
        sourceVersion: 'v1',
        providerKey: 'provider-a',
        idempotencyKey: 'complaint-1',
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        effectiveAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        freshUntil: null,
        immutableEvidence: ['recipient_email' => 'person@example.com'],
    ))->toThrow(InvalidArgumentException::class, 'secret or direct-PII');

    expect(fn () => new SuppressionRecord(
        id: 'suppression-2',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        channel: 'email',
        purpose: 'marketing',
        authorityType: SuppressionAuthorityType::Complaint,
        sourceType: 'provider_webhook',
        sourceVersion: 'v1',
        providerKey: 'provider-a',
        idempotencyKey: 'complaint-2',
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        effectiveAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        freshUntil: null,
        immutableEvidence: ['credential' => 'should-not-be-here'],
    ))->toThrow(InvalidArgumentException::class, 'secret or direct-PII');
});

it('keeps preference evidence distinct from suppression authority', function () {
    $preference = new PreferenceRecord(
        id: 'preference-1',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        channel: 'email',
        purpose: 'marketing',
        scopeType: 'list',
        scopeKey: 'weekly',
        decision: PreferenceDecision::OptIn,
        basisType: 'explicit_consent',
        sourceType: 'preference_center',
        sourceVersion: 'v1',
        idempotencyKey: 'preference-1',
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        effectiveAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        immutableEvidence: ['interaction' => 'opt_in'],
    );

    expect($preference->decision)->toBe(PreferenceDecision::OptIn)
        ->and($preference->evidenceFingerprint)->toHaveLength(64);
});

it('enforces database-facing string bounds in suppression and preference records', function () {
    expect(fn () => new SuppressionRecord(
        id: 'suppression-1',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        channel: str_repeat('x', 65),
        purpose: 'marketing',
        authorityType: SuppressionAuthorityType::Unsubscribe,
        sourceType: 'rfc8058',
        sourceVersion: null,
        providerKey: null,
        idempotencyKey: 'key',
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        effectiveAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        freshUntil: null,
        immutableEvidence: [],
    ))->toThrow(InvalidArgumentException::class, 'channel exceeds storage limit');

    expect(fn () => new PreferenceRecord(
        id: 'preference-1',
        workspaceId: 'workspace-1',
        contactId: 'contact-1',
        channel: 'email',
        purpose: 'marketing',
        scopeType: 'list',
        scopeKey: str_repeat('x', 192),
        decision: PreferenceDecision::OptOut,
        basisType: null,
        sourceType: 'preference_center',
        sourceVersion: null,
        idempotencyKey: 'key',
        observedAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        effectiveAt: new DateTimeImmutable('2026-09-17T00:00:00+00:00'),
        immutableEvidence: [],
    ))->toThrow(InvalidArgumentException::class, 'scopeKey exceeds storage limit');
});
