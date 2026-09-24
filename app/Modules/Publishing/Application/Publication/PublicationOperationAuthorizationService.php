<?php

namespace App\Modules\Publishing\Application\Publication;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Connectors\ProviderOperationStatus;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\ProviderConnection;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use App\Modules\Publishing\Domain\Publication\PublicationAttempt;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Domain\Publication\PublicationOperation;
use App\Modules\Publishing\Domain\Publication\PublicationOperationAuthorization;
use App\Modules\Publishing\Domain\Publication\PublicationStatusProjection;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationStatusRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PublicationOperationAuthorizationService
{
    public function __construct(
        private DatabasePublicationAttemptRepository $attempts,
        private DatabasePublicationStatusRepository $statuses,
        private ProviderRepository $providers,
    ) {}

    public function authorize(
        string $workspaceId,
        string $publicationAttemptId,
        PublicationOperation $operation,
        DateTimeImmutable $at,
    ): PublicationOperationAuthorization {
        CampaignPayloadGuard::assertIdentifier($workspaceId, 'publicationOperation.workspaceId');
        CampaignPayloadGuard::assertIdentifier($publicationAttemptId, 'publicationOperation.publicationAttemptId');

        $authorizedAt = $at->setTimezone(new DateTimeZone('UTC'));
        $attempt = $this->attempts->find($workspaceId, $publicationAttemptId);
        if ($attempt === null) {
            throw new InvalidArgumentException('Publication operation attempt does not exist in this workspace.');
        }

        $projection = $this->statuses->findProjection($workspaceId, $attempt->id);
        if ($projection === null) {
            throw new InvalidArgumentException('Publication operation requires trusted provider status evidence.');
        }

        $this->assertOperationState($attempt, $projection, $operation);

        $connection = $this->providers->findConnection($workspaceId, $attempt->providerConnectionId);
        if ($connection === null) {
            throw new InvalidArgumentException('Publication operation provider connection is unavailable.');
        }

        $providerCapabilityOperation = $operation->providerCapabilityOperation();
        $capability = $this->providers->findCapabilityForOperation(
            workspaceId: $workspaceId,
            providerId: $attempt->providerId,
            connectionId: $attempt->providerConnectionId,
            operation: $providerCapabilityOperation,
        );
        if ($capability === null) {
            throw new InvalidArgumentException(
                'Publication operation current provider capability evidence is unavailable for '.$providerCapabilityOperation.'.',
            );
        }

        $this->assertCurrentAuthority($attempt, $connection, $capability, $providerCapabilityOperation, $authorizedAt);

        return new PublicationOperationAuthorization(
            workspaceId: $attempt->workspaceId,
            publicationAttemptId: $attempt->id,
            operation: $operation,
            providerCapabilityOperation: $providerCapabilityOperation,
            providerId: $attempt->providerId,
            providerConnectionId: $attempt->providerConnectionId,
            originalCapabilityEvidenceId: $attempt->capabilityEvidenceId,
            currentCapabilityEvidenceId: $capability->id,
            currentCapabilitySourceVersion: (string) $capability->sourceVersion,
            connectionSourceVersion: (string) $connection->sourceVersion,
            attemptHash: $attempt->attemptHash,
            projectionObservationHash: $projection->currentObservationHash,
            projectionVersion: $projection->projectionVersion,
            providerOperationId: $projection->providerOperationId,
            authorizedAt: $authorizedAt,
        );
    }

    private function assertOperationState(
        PublicationAttempt $attempt,
        PublicationStatusProjection $projection,
        PublicationOperation $operation,
    ): void {
        if ($operation === PublicationOperation::Retry) {
            if (
                $attempt->state !== PublicationAttemptState::FailedRetriable
                || $projection->normalizedStatus !== ProviderOperationStatus::Failed
            ) {
                throw new InvalidArgumentException(
                    'Publication retry authorization requires failed_retriable attempt state plus trusted failed provider evidence.',
                );
            }

            return;
        }

        if (
            $attempt->state !== PublicationAttemptState::Published
            || $projection->normalizedStatus !== ProviderOperationStatus::Succeeded
        ) {
            throw new InvalidArgumentException(
                'Publication edit/delete authorization requires a trusted successful publication.',
            );
        }
    }

    private function assertCurrentAuthority(
        PublicationAttempt $attempt,
        ProviderConnection $connection,
        ProviderCapability $capability,
        string $providerCapabilityOperation,
        DateTimeImmutable $authorizedAt,
    ): void {
        if (
            $connection->workspaceId !== $attempt->workspaceId
            || $connection->id !== $attempt->providerConnectionId
            || $connection->providerId !== $attempt->providerId
            || $connection->readiness !== ProviderReadinessStatus::Ready
        ) {
            throw new InvalidArgumentException('Publication operation provider connection is not ready for this attempt.');
        }

        if (
            $capability->workspaceId !== $attempt->workspaceId
            || $capability->providerId !== $attempt->providerId
            || $capability->connectionId !== $attempt->providerConnectionId
            || $capability->operation !== $providerCapabilityOperation
            || $capability->support !== CapabilitySupport::Supported
        ) {
            throw new InvalidArgumentException('Publication operation exact provider capability is not supported.');
        }

        if (
            $connection->sourceVersion === null
            || trim($connection->sourceVersion) === ''
            || $capability->sourceVersion === null
            || trim($capability->sourceVersion) === ''
        ) {
            throw new InvalidArgumentException('Publication operation requires versioned provider connection and capability evidence.');
        }

        if (
            $connection->observedAt > $authorizedAt
            || $capability->observedAt > $authorizedAt
            || ($connection->freshUntil !== null && $connection->freshUntil <= $authorizedAt)
            || ($capability->freshUntil !== null && $capability->freshUntil <= $authorizedAt)
            || ($connection->tokenExpiresAt !== null && $connection->tokenExpiresAt <= $authorizedAt)
        ) {
            throw new InvalidArgumentException('Publication operation provider authority is stale, expired or not yet effective.');
        }

        if (
            $connection->providerReviewStatus !== null
            && mb_strtolower(trim($connection->providerReviewStatus)) !== 'approved'
        ) {
            throw new InvalidArgumentException('Publication operation provider app-review authority is not approved.');
        }

        $requiredScopes = $this->stringList($capability->requiredScopes, 'capability.requiredScopes');
        $requiredRoles = $this->stringList($capability->requiredRoles, 'capability.requiredRoles');
        $grantedScopes = $this->stringList($connection->grantedScopes, 'connection.grantedScopes');
        $roles = $this->stringList($connection->roles, 'connection.roles');

        if (array_diff($requiredScopes, $grantedScopes) !== []) {
            throw new InvalidArgumentException('Publication operation provider scopes are insufficient.');
        }

        if (array_diff($requiredRoles, $roles) !== []) {
            throw new InvalidArgumentException('Publication operation provider account roles are insufficient.');
        }
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values, string $field): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Publication operation '.$field.' must be a list.');
        }

        $result = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Publication operation '.$field.' must contain non-empty strings.');
            }

            $result[] = $value;
        }

        return $result;
    }
}
