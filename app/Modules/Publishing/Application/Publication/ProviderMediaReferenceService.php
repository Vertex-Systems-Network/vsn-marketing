<?php

namespace App\Modules\Publishing\Application\Publication;

use App\Modules\Assets\Infrastructure\Persistence\DatabaseAssetRepository;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use App\Modules\Publishing\Domain\Publication\ProviderMediaAssetReferenceKind;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReference;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReferenceKind;
use App\Modules\Publishing\Domain\Publication\ProviderMediaReferenceState;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseProviderMediaReferenceRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class ProviderMediaReferenceService
{
    public function __construct(
        private DatabaseManager $database,
        private IdentifierGenerator $identifiers,
        private DatabaseCampaignRepository $campaigns,
        private DatabaseAssetRepository $assets,
        private DatabasePublicationAttemptRepository $attempts,
        private DatabaseProviderMediaReferenceRepository $references,
    ) {}

    public function registerObservedReference(
        string $workspaceId,
        string $publicationAttemptId,
        string $assetReferenceId,
        ProviderMediaReferenceKind $providerReferenceKind,
        string $providerReference,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $observedAt,
    ): ProviderMediaReference {
        CampaignPayloadGuard::assertIdentifier($workspaceId, 'providerMediaReference.workspaceId');
        CampaignPayloadGuard::assertIdentifier($publicationAttemptId, 'providerMediaReference.publicationAttemptId');
        CampaignPayloadGuard::assertIdentifier($assetReferenceId, 'providerMediaReference.assetReferenceId');

        $at = $observedAt->setTimezone(new DateTimeZone('UTC'));
        $expiry = $expiresAt?->setTimezone(new DateTimeZone('UTC'));

        return $this->database->connection()->transaction(function () use (
            $workspaceId,
            $publicationAttemptId,
            $assetReferenceId,
            $providerReferenceKind,
            $providerReference,
            $expiry,
            $at,
        ): ProviderMediaReference {
            $attempt = $this->attempts->find($workspaceId, $publicationAttemptId, true);
            if ($attempt === null) {
                throw new InvalidArgumentException('Provider media publication attempt does not exist in this workspace.');
            }

            if (in_array($attempt->state, [
                PublicationAttemptState::Published,
                PublicationAttemptState::FailedTerminal,
                PublicationAttemptState::Cancelled,
            ], true)) {
                throw new InvalidArgumentException('Provider media reference cannot be registered for a terminal publication attempt.');
            }

            $snapshot = $this->campaigns->findSnapshot($workspaceId, $attempt->snapshotId);
            if ($snapshot === null || ! in_array($assetReferenceId, $snapshot->assetReferenceIds, true)) {
                throw new InvalidArgumentException('Provider media asset version is not pinned by the immutable campaign snapshot.');
            }

            $original = $this->assets->findOriginal($workspaceId, $assetReferenceId);
            $variant = null;
            $assetReferenceKind = ProviderMediaAssetReferenceKind::Original;

            if ($original === null) {
                $variant = $this->assets->findVariant($workspaceId, $assetReferenceId);
                if ($variant === null) {
                    throw new InvalidArgumentException('Provider media canonical asset version does not exist in this workspace.');
                }

                $original = $this->assets->findOriginal($workspaceId, $variant->sourceOriginalId);
                if ($original === null) {
                    throw new InvalidArgumentException('Provider media variant source original is unavailable.');
                }

                $assetReferenceKind = ProviderMediaAssetReferenceKind::Variant;
            }

            $contentSha256 = $variant?->output->contentSha256 ?? $original->observation->contentSha256;

            return $this->references->create(ProviderMediaReference::register(
                id: $this->identifiers->next(),
                workspaceId: $workspaceId,
                publicationAttemptId: $attempt->id,
                snapshotId: $attempt->snapshotId,
                assetId: $original->assetId,
                assetOriginalId: $original->id,
                assetVariantId: $variant?->id,
                assetReferenceKind: $assetReferenceKind,
                canonicalAssetReferenceId: $assetReferenceId,
                assetContentSha256: $contentSha256,
                providerConnectionId: $attempt->providerConnectionId,
                capabilityEvidenceId: $attempt->capabilityEvidenceId,
                providerId: $attempt->providerId,
                providerReferenceKind: $providerReferenceKind,
                providerReference: $providerReference,
                expiresAt: $expiry,
                createdAt: $at,
            ));
        });
    }

    public function transition(
        string $workspaceId,
        string $referenceId,
        ProviderMediaReferenceState $next,
        DateTimeImmutable $observedAt,
    ): ProviderMediaReference {
        CampaignPayloadGuard::assertIdentifier($workspaceId, 'providerMediaReference.workspaceId');
        CampaignPayloadGuard::assertIdentifier($referenceId, 'providerMediaReference.id');
        $at = $observedAt->setTimezone(new DateTimeZone('UTC'));

        return $this->database->connection()->transaction(function () use (
            $workspaceId,
            $referenceId,
            $next,
            $at,
        ): ProviderMediaReference {
            $stored = $this->references->find($workspaceId, $referenceId, true);
            if ($stored === null) {
                throw new InvalidArgumentException('Provider media reference does not exist in this workspace.');
            }

            $transitioned = $stored->transitionTo($next, $at);

            return $this->references->transition($transitioned, $stored->stateVersion);
        });
    }
}
