<?php

use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationDimension;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidence;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidenceEvaluator;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidenceStatus;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationReadiness;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderRecordGuard;
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicy;
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicyEvaluator;
use App\Modules\Providers\Domain\SenderPolicy\ProviderVolumeClassification;

function task0026SecurityEvidence(
    AuthenticationDimension $dimension,
    AuthenticationEvidenceStatus $status = AuthenticationEvidenceStatus::Pass,
    string $workspaceId = 'workspace-1',
    string $version = 'v1',
    ?DateTimeImmutable $observedAt = null,
    ?DateTimeImmutable $freshUntil = null,
    ?DateTimeImmutable $recordedAt = null,
    array $publicMaterial = [],
    array $redactedEvidence = [],
): AuthenticationEvidence {
    $observedAt ??= new DateTimeImmutable('2026-09-16T10:00:00+00:00');
    $freshUntil ??= new DateTimeImmutable('2026-09-17T10:00:00+00:00');
    $recordedAt ??= new DateTimeImmutable('2026-09-16T10:01:00+00:00');

    return new AuthenticationEvidence(
        id: 'evidence-'.$dimension->value.'-'.$version.'-'.$status->value,
        workspaceId: $workspaceId,
        senderDomainId: 'sender-domain-1',
        dimension: $dimension,
        status: $status,
        evidenceVersion: $version,
        sourceType: 'dns_observation',
        providerKey: 'gmail',
        publicMaterial: $publicMaterial,
        redactedEvidence: $redactedEvidence,
        sourceUrl: 'https://support.google.com/a/answer/81126',
        sourceVersion: '2026-09',
        observedAt: $observedAt,
        freshUntil: $freshUntil,
        recordedAt: $recordedAt,
    );
}

/** @return list<AuthenticationEvidence> */
function task0026PassingSecurityEvidence(): array
{
    return array_map(
        static fn (AuthenticationDimension $dimension): AuthenticationEvidence => task0026SecurityEvidence($dimension),
        AuthenticationDimension::cases(),
    );
}

function task0026SecurityPolicy(
    string $workspaceId = 'workspace-1',
    ?DateTimeImmutable $freshUntil = null,
    ?int $threshold = 5000,
    ?string $thresholdUnit = 'messages_per_day',
    array $requirements = ['dmarc' => true],
): MailboxProviderPolicy {
    return new MailboxProviderPolicy(
        id: 'policy-1',
        workspaceId: $workspaceId,
        providerKey: 'gmail',
        policyKey: 'bulk-sender',
        policyVersion: '2026-09',
        effectiveFrom: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        effectiveUntil: null,
        highVolumeThreshold: $threshold,
        thresholdUnit: $thresholdUnit,
        classificationInputs: ['source' => 'official'],
        requirements: $requirements,
        provenanceUrl: 'https://support.google.com/a/answer/81126',
        sourceVersion: '2026-09',
        observedAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
        freshUntil: $freshUntil ?? new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
    );
}

it('rejects cross-workspace authentication evidence instead of evaluating foreign tenant data', function () {
    $evidence = task0026PassingSecurityEvidence();
    $evidence[0] = task0026SecurityEvidence(
        AuthenticationDimension::Spf,
        workspaceId: 'workspace-2',
    );

    expect(fn () => (new AuthenticationEvidenceEvaluator)->evaluate(
        workspaceId: 'workspace-1',
        senderDomainId: 'sender-domain-1',
        evidence: $evidence,
        at: new DateTimeImmutable('2026-09-16T12:00:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'must match the evaluated workspace and sender domain');
});

it('fails closed when authentication evidence is missing or stale', function () {
    $evaluator = new AuthenticationEvidenceEvaluator;
    $at = new DateTimeImmutable('2026-09-16T12:00:00+00:00');

    $missing = $evaluator->evaluate('workspace-1', 'sender-domain-1', [], $at);

    $staleEvidence = array_map(
        static fn (AuthenticationDimension $dimension): AuthenticationEvidence => task0026SecurityEvidence(
            dimension: $dimension,
            observedAt: new DateTimeImmutable('2026-09-15T09:00:00+00:00'),
            freshUntil: new DateTimeImmutable('2026-09-16T09:00:00+00:00'),
            recordedAt: new DateTimeImmutable('2026-09-15T09:01:00+00:00'),
        ),
        AuthenticationDimension::cases(),
    );
    $stale = $evaluator->evaluate('workspace-1', 'sender-domain-1', $staleEvidence, $at);

    expect($missing->readiness)->toBe(AuthenticationReadiness::Unknown)
        ->and($missing->isReady())->toBeFalse()
        ->and($stale->readiness)->toBe(AuthenticationReadiness::Stale)
        ->and($stale->isReady())->toBeFalse();
});

it('fails closed on contradictory authentication observations', function () {
    $evidence = task0026PassingSecurityEvidence();
    $evidence[] = task0026SecurityEvidence(
        dimension: AuthenticationDimension::Spf,
        status: AuthenticationEvidenceStatus::Fail,
        version: 'v2',
    );

    $decision = (new AuthenticationEvidenceEvaluator)->evaluate(
        workspaceId: 'workspace-1',
        senderDomainId: 'sender-domain-1',
        evidence: $evidence,
        at: new DateTimeImmutable('2026-09-16T12:00:00+00:00'),
    );

    expect($decision->readiness)->toBe(AuthenticationReadiness::Contradictory)
        ->and($decision->isReady())->toBeFalse()
        ->and($decision->reasons)->toContain('spf:contradictory');
});

it('rejects secret and private signing material recursively from sender evidence payloads', function () {
    expect(fn () => SenderRecordGuard::assertNoSecretMaterial([
        'nested' => ['api_key' => 'must-not-cross-domain-boundary'],
    ]))->toThrow(InvalidArgumentException::class, 'must not contain credential or private signing material');

    expect(fn () => task0026SecurityEvidence(
        dimension: AuthenticationDimension::Dkim,
        redactedEvidence: ['material' => '-----BEGIN PRIVATE KEY-----'],
    ))->toThrow(InvalidArgumentException::class, 'must not contain private key material');
});

it('rejects malformed or secret-bearing provider policy instead of normalizing it', function () {
    expect(fn () => task0026SecurityPolicy(thresholdUnit: null))
        ->toThrow(InvalidArgumentException::class, 'Threshold value and unit must be supplied together');

    expect(fn () => task0026SecurityPolicy(requirements: [
        'nested' => ['access_token' => 'must-not-be-policy-data'],
    ]))->toThrow(InvalidArgumentException::class, 'must not contain secret material');
});

it('keeps stale or foreign provider policy fail closed', function () {
    $evaluator = new MailboxProviderPolicyEvaluator;
    $at = new DateTimeImmutable('2026-09-16T12:00:00+00:00');

    $stale = $evaluator->decide(
        workspaceId: 'workspace-1',
        providerKey: 'gmail',
        policies: [task0026SecurityPolicy(freshUntil: new DateTimeImmutable('2026-09-10T00:00:00+00:00'))],
        at: $at,
        observedVolume: 6000,
    );

    $foreign = $evaluator->decide(
        workspaceId: 'workspace-1',
        providerKey: 'gmail',
        policies: [task0026SecurityPolicy(workspaceId: 'workspace-2')],
        at: $at,
        observedVolume: 6000,
    );

    expect($stale->classification)->toBe(ProviderVolumeClassification::Unknown)
        ->and($stale->reasons)->toContain('stale_or_unknown_policy_freshness')
        ->and($foreign->classification)->toBe(ProviderVolumeClassification::Unknown)
        ->and($foreign->reasons)->toContain('missing_effective_policy');
});
