<?php

namespace App\Modules\Publishing\Application\Publication;

use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Connectors\ReconciliationSource;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use App\Modules\Publishing\Domain\Publication\PublicationStatusObservation;
use App\Modules\Publishing\Domain\Publication\PublicationStatusReconciliationResult;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationStatusRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PublicationStatusReconciliationService
{
    public function __construct(
        private IdentifierGenerator $identifiers,
        private DatabasePublicationAttemptRepository $attempts,
        private DatabasePublicationStatusRepository $statuses,
    ) {}

    /** @param array<string, mixed> $evidence */
    public function observe(
        string $workspaceId,
        string $publicationAttemptId,
        string $providerOperationId,
        ProviderOperationStatus $normalizedStatus,
        string $providerStatus,
        ReconciliationSource $source,
        string $sourceReference,
        DateTimeImmutable $providerObservedAt,
        DateTimeImmutable $receivedAt,
        array $evidence = [],
    ): PublicationStatusReconciliationResult {
        CampaignPayloadGuard::assertIdentifier($workspaceId, 'publicationStatus.workspaceId');
        CampaignPayloadGuard::assertIdentifier($publicationAttemptId, 'publicationStatus.publicationAttemptId');

        $attempt = $this->attempts->find($workspaceId, $publicationAttemptId);
        if ($attempt === null) {
            throw new InvalidArgumentException('Publication status attempt does not exist in this workspace.');
        }

        $observedAt = $providerObservedAt->setTimezone(new DateTimeZone('UTC'));
        $received = $receivedAt->setTimezone(new DateTimeZone('UTC'));

        return $this->statuses->record(PublicationStatusObservation::record(
            id: $this->identifiers->next(),
            workspaceId: $workspaceId,
            publicationAttemptId: $attempt->id,
            providerConnectionId: $attempt->providerConnectionId,
            capabilityEvidenceId: $attempt->capabilityEvidenceId,
            providerId: $attempt->providerId,
            providerOperationId: $providerOperationId,
            normalizedStatus: $normalizedStatus,
            providerStatus: $providerStatus,
            source: $source,
            sourceReference: $sourceReference,
            providerObservedAt: $observedAt,
            receivedAt: $received,
            evidence: $evidence,
        ));
    }
}
