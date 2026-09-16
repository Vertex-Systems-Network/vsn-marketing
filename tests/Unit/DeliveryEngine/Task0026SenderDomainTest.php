<?php

use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderDomain;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderDomainLifecycle;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderDomainName;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderEligibilityContext;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderIdentity;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderIdentityLifecycle;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderPurpose;
use DateTimeImmutable;
use InvalidArgumentException;

function task0026Eligibility(
    ?string $providerKey = 'ses',
    ?string $policyKey = 'bulk-sender',
    ?string $policyVersion = '2026-01',
    array $policyContext = ['source' => 'official'],
): SenderEligibilityContext {
    return new SenderEligibilityContext(
        purpose: SenderPurpose::Marketing,
        providerKey: $providerKey,
        policyKey: $policyKey,
        policyVersion: $policyVersion,
        policyContext: $policyContext,
    );
}

function task0026Identity(
    string $localPart = 'news',
    ?string $displayName = 'VSN News',
    ?string $replyToAddress = 'reply@example.com',
    ?string $providerConnectionId = 'provider-connection-1',
    string $idempotencyKey = 'sender-identity-1',
    ?SenderEligibilityContext $eligibility = null,
    ?DateTimeImmutable $createdAt = null,
    ?DateTimeImmutable $updatedAt = null,
    array $metadata = ['source' => 'operator'],
): SenderIdentity {
    $createdAt ??= new DateTimeImmutable('2026-09-16T10:00:00+00:00');
    $updatedAt ??= $createdAt;

    return new SenderIdentity(
        id: 'sender-identity-1',
        workspaceId: 'workspace-1',
        senderDomainId: 'sender-domain-1',
        domain: new SenderDomainName('Example.COM.'),
        localPart: $localPart,
        displayName: $displayName,
        replyToAddress: $replyToAddress,
        lifecycle: SenderIdentityLifecycle::Registered,
        eligibility: $eligibility ?? task0026Eligibility(),
        providerConnectionId: $providerConnectionId,
        idempotencyKey: $idempotencyKey,
        metadata: $metadata,
        createdAt: $createdAt,
        updatedAt: $updatedAt,
    );
}

it('canonicalizes sender domains and keeps workspace identity deterministic', function () {
    $domain = new SenderDomain(
        id: 'sender-domain-1',
        workspaceId: 'workspace-1',
        domain: new SenderDomainName(' Example.COM. '),
        lifecycle: SenderDomainLifecycle::Registered,
        idempotencyKey: 'sender-domain-1',
        metadata: ['source' => 'operator'],
        createdAt: new DateTimeImmutable('2026-09-16T10:00:00+00:00'),
        updatedAt: new DateTimeImmutable('2026-09-16T10:00:00+00:00'),
    );

    expect((string) $domain->domain)->toBe('example.com')
        ->and($domain->identityKey())->toBe('workspace-1:example.com');
});

it('enforces terminal and reversible sender domain lifecycle rules', function () {
    $createdAt = new DateTimeImmutable('2026-09-16T10:00:00+00:00');
    $domain = new SenderDomain(
        id: 'sender-domain-1',
        workspaceId: 'workspace-1',
        domain: new SenderDomainName('example.com'),
        lifecycle: SenderDomainLifecycle::Registered,
        idempotencyKey: 'sender-domain-1',
        metadata: [],
        createdAt: $createdAt,
        updatedAt: $createdAt,
    );

    $managed = $domain->transitionTo(SenderDomainLifecycle::Managed, new DateTimeImmutable('2026-09-16T10:01:00+00:00'));
    $suspended = $managed->transitionTo(SenderDomainLifecycle::Suspended, new DateTimeImmutable('2026-09-16T10:02:00+00:00'));
    $resumed = $suspended->transitionTo(SenderDomainLifecycle::Managed, new DateTimeImmutable('2026-09-16T10:03:00+00:00'));
    $retired = $resumed->transitionTo(SenderDomainLifecycle::Retired, new DateTimeImmutable('2026-09-16T10:04:00+00:00'));

    expect($retired->lifecycle)->toBe(SenderDomainLifecycle::Retired);

    expect(fn () => $retired->transitionTo(SenderDomainLifecycle::Managed, new DateTimeImmutable('2026-09-16T10:05:00+00:00')))
        ->toThrow(InvalidArgumentException::class, 'Invalid sender domain lifecycle transition');
});

it('fails before persistence when sender domain storage bounds are exceeded', function () {
    expect(fn () => new SenderDomain(
        id: 'sender-domain-1',
        workspaceId: 'workspace-1',
        domain: new SenderDomainName('example.com'),
        lifecycle: SenderDomainLifecycle::Registered,
        idempotencyKey: str_repeat('a', 192),
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-16T10:00:00+00:00'),
        updatedAt: new DateTimeImmutable('2026-09-16T10:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'must not exceed 191 characters');
});

it('requires complete bounded provider policy context', function () {
    expect(fn () => task0026Eligibility(policyVersion: null))
        ->toThrow(InvalidArgumentException::class, 'Policy key and policy version must be supplied together.');

    expect(fn () => task0026Eligibility(providerKey: str_repeat('p', 121)))
        ->toThrow(InvalidArgumentException::class, 'Provider key must not exceed 120 characters.');

    expect(fn () => task0026Eligibility(policyKey: str_repeat('k', 121)))
        ->toThrow(InvalidArgumentException::class, 'Policy key must not exceed 120 characters.');

    expect(fn () => task0026Eligibility(policyVersion: str_repeat('v', 121)))
        ->toThrow(InvalidArgumentException::class, 'Policy version must not exceed 120 characters.');
});

it('builds deterministic sender addresses and identity keys', function () {
    $identity = task0026Identity();

    expect($identity->emailAddress())->toBe('news@example.com')
        ->and($identity->identityKey())->toBe('workspace-1:news@example.com');
});

it('fails before persistence when sender identity storage bounds are exceeded', function () {
    expect(fn () => task0026Identity(localPart: str_repeat('l', 65)))
        ->toThrow(InvalidArgumentException::class, 'local part must not exceed 64 characters');

    expect(fn () => task0026Identity(displayName: str_repeat('d', 192)))
        ->toThrow(InvalidArgumentException::class, 'display name must not exceed 191 characters');

    expect(fn () => task0026Identity(idempotencyKey: str_repeat('i', 192)))
        ->toThrow(InvalidArgumentException::class, 'idempotency key must not exceed 191 characters');
});

it('requires provider context when a provider connection is bound', function () {
    expect(fn () => task0026Identity(
        eligibility: task0026Eligibility(providerKey: null, policyKey: null, policyVersion: null),
    ))->toThrow(InvalidArgumentException::class, 'Provider context is required when a provider connection is supplied.');
});

it('rejects credential material recursively from canonical sender records', function () {
    expect(fn () => task0026Identity(metadata: [
        'nested' => [
            'api_key' => 'must-never-be-persisted',
        ],
    ]))->toThrow(InvalidArgumentException::class, 'must not contain credential or private signing material');

    expect(fn () => task0026Eligibility(policyContext: [
        'material' => '-----BEGIN PRIVATE KEY-----',
    ]))->toThrow(InvalidArgumentException::class, 'must not contain private key material');
});

it('keeps sender identity lifecycle and update timestamps monotonic', function () {
    $identity = task0026Identity();
    $managed = $identity->transitionTo(SenderIdentityLifecycle::Managed, new DateTimeImmutable('2026-09-16T10:01:00+00:00'));
    $retired = $managed->transitionTo(SenderIdentityLifecycle::Retired, new DateTimeImmutable('2026-09-16T10:02:00+00:00'));

    expect($retired->lifecycle)->toBe(SenderIdentityLifecycle::Retired);

    expect(fn () => $retired->transitionTo(SenderIdentityLifecycle::Managed, new DateTimeImmutable('2026-09-16T10:03:00+00:00')))
        ->toThrow(InvalidArgumentException::class, 'Invalid sender identity lifecycle transition');

    expect(fn () => $managed->withEligibility(
        task0026Eligibility(policyVersion: '2026-02'),
        new DateTimeImmutable('2026-09-16T09:59:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'update time must not move backwards');
});
