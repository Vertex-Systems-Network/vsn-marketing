<?php

use App\Modules\Publishing\Domain\Publication\ProviderMediaAssetReferenceKind;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReference;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReferenceKind;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReferenceState;
use DateTimeImmutable;
use InvalidArgumentException;

function task0040ProviderMediaReference(string $providerReference = 'media_123'): ProviderMediaReference
{
    return ProviderMediaReference::register(
        id: 'reference-1',
        workspaceId: 'workspace-1',
        publicationAttemptId: 'attempt-1',
        snapshotId: 'snapshot-1',
        assetId: 'asset-1',
        assetOriginalId: 'original-1',
        assetVariantId: null,
        assetReferenceKind: ProviderMediaAssetReferenceKind::Original,
        canonicalAssetReferenceId: 'original-1',
        assetContentSha256: str_repeat('a', 64),
        providerConnectionId: 'connection-1',
        capabilityEvidenceId: 'capability-1',
        providerId: 'provider-1',
        providerReferenceKind: ProviderMediaReferenceKind::Media,
        providerReference: $providerReference,
        expiresAt: new DateTimeImmutable('2026-07-15T14:30:00+00:00'),
        createdAt: new DateTimeImmutable('2026-07-15T13:30:30+00:00'),
    );
}

it('derives stable media-reference idempotency from exact attempt asset and provider reference kind', function () {
    $first = task0040ProviderMediaReference();
    $second = ProviderMediaReference::register(
        id: 'reference-2',
        workspaceId: $first->workspaceId,
        publicationAttemptId: $first->publicationAttemptId,
        snapshotId: $first->snapshotId,
        assetId: $first->assetId,
        assetOriginalId: $first->assetOriginalId,
        assetVariantId: null,
        assetReferenceKind: $first->assetReferenceKind,
        canonicalAssetReferenceId: $first->canonicalAssetReferenceId,
        assetContentSha256: $first->assetContentSha256,
        providerConnectionId: $first->providerConnectionId,
        capabilityEvidenceId: $first->capabilityEvidenceId,
        providerId: $first->providerId,
        providerReferenceKind: $first->providerReferenceKind,
        providerReference: $first->providerReference,
        expiresAt: $first->expiresAt,
        createdAt: new DateTimeImmutable('2026-07-15T13:30:31+00:00'),
    );

    expect($second->idempotencyKey)->toBe($first->idempotencyKey)
        ->and($second->referenceHash)->toBe($first->referenceHash)
        ->and($first->state)->toBe(ProviderMediaReferenceState::Pending);
});

it('keeps derivative processing state monotonic and expires ready references explicitly', function () {
    $pending = task0040ProviderMediaReference();
    $processing = $pending->transitionTo(
        ProviderMediaReferenceState::Processing,
        new DateTimeImmutable('2026-07-15T13:31:00+00:00'),
    );
    $ready = $processing->transitionTo(
        ProviderMediaReferenceState::Ready,
        new DateTimeImmutable('2026-07-15T13:32:00+00:00'),
    );
    $expired = $ready->transitionTo(
        ProviderMediaReferenceState::Expired,
        new DateTimeImmutable('2026-07-15T14:31:00+00:00'),
    );

    expect($processing->stateVersion)->toBe(2)
        ->and($ready->stateVersion)->toBe(3)
        ->and($expired->stateVersion)->toBe(4)
        ->and($expired->referenceHash)->toBe($pending->referenceHash);

    expect(fn () => $expired->transitionTo(
        ProviderMediaReferenceState::Processing,
        new DateTimeImmutable('2026-07-15T14:32:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'cannot transition');
});

it('rejects provider media references that are remote URLs rather than opaque derivative identifiers', function () {
    expect(fn () => task0040ProviderMediaReference('https://attacker.example/media.png'))
        ->toThrow(InvalidArgumentException::class, 'cannot be a remote-media URL');
});
