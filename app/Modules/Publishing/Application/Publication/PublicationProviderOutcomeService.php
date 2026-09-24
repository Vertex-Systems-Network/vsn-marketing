<?php

namespace App\Modules\Publishing\Application\Publication;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\Connectors\NormalizedProviderError;
use App\Modules\Providers\Domain\Connectors\ProviderErrorCategory;
use App\Modules\Providers\Domain\Contracts\ProviderRepository;
use App\Modules\Providers\Domain\ProviderCapability;
use App\Modules\Providers\Domain\ProviderConnection;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use App\Modules\Publishing\Domain\Publication\PublicationOperationAuthorization;
use App\Modules\Publishing\Domain\Publication\PublicationPolicyBoundaryEvidence;
use App\Modules\Publishing\Domain\Publication\PublicationProviderCircuitState;
use App\Modules\Publishing\Domain\Publication\PublicationProviderOutcome;
use App\Modules\Publishing\Domain\Publication\PublicationProviderOutcomeCode;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationStatusRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PublicationProviderOutcomeService
{
    public function __construct(
        private DatabasePublicationAttemptRepository $attempts,
        private DatabasePublicationStatusRepository $statuses,
        private ProviderRepository $providers,
    ) {}

    public function assess(
        PublicationOperationAuthorization $authorization,
        PublicationPolicyBoundaryEvidence $boundaries,
        PublicationProviderCircuitState $circuitState,
        ?NormalizedProviderError $providerError,
        DateTimeImmutable $at,
    ): PublicationProviderOutcome {
        $evaluatedAt = $at->setTimezone(new DateTimeZone('UTC'));
        $attempt = $this->attempts->find($authorization->workspaceId, $authorization->publicationAttemptId);
        if ($attempt === null) {
            throw new InvalidArgumentException('Publication provider outcome attempt is unavailable.');
        }

        $projection = $this->statuses->findProjection($authorization->workspaceId, $authorization->publicationAttemptId);
        if ($projection === null) {
            throw new InvalidArgumentException('Publication provider outcome requires trusted status projection evidence.');
        }

        if (
            ! hash_equals($attempt->attemptHash, $authorization->attemptHash)
            || ! hash_equals($projection->currentObservationHash, $authorization->projectionObservationHash)
            || $projection->projectionVersion !== $authorization->projectionVersion
            || $projection->providerOperationId !== $authorization->providerOperationId
            || $attempt->providerId !== $authorization->providerId
            || $attempt->providerConnectionId !== $authorization->providerConnectionId
        ) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::AuthorizationEvidenceDrift,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
            );
        }

        if (! $boundaries->allPass()) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::PolicyBoundaryDenied,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
                failedPolicyBoundary: $boundaries->firstFailure(),
            );
        }

        $connection = $this->providers->findConnection(
            $authorization->workspaceId,
            $authorization->providerConnectionId,
        );
        if ($connection === null) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::ProviderDisconnected,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
            );
        }

        $readinessOutcome = $this->readinessOutcome($connection);
        if ($readinessOutcome !== null) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: $readinessOutcome,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
            );
        }

        if ($connection->tokenExpiresAt !== null && $connection->tokenExpiresAt <= $evaluatedAt) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::CredentialInvalid,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
            );
        }

        if (
            $connection->providerReviewStatus !== null
            && mb_strtolower(trim($connection->providerReviewStatus)) !== 'approved'
        ) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::AppReviewRestricted,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
            );
        }

        $capability = $this->providers->findCapabilityForOperation(
            workspaceId: $authorization->workspaceId,
            providerId: $authorization->providerId,
            connectionId: $authorization->providerConnectionId,
            operation: $authorization->providerCapabilityOperation,
        );

        if ($capability === null || $this->capabilityDrifted($authorization, $capability)) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::CapabilityVersionDrift,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
                capability: $capability,
            );
        }

        if (
            $connection->sourceVersion === null
            || trim($connection->sourceVersion) === ''
            || $connection->observedAt > $evaluatedAt
            || $capability->observedAt > $evaluatedAt
            || ($connection->freshUntil !== null && $connection->freshUntil <= $evaluatedAt)
            || ($capability->freshUntil !== null && $capability->freshUntil <= $evaluatedAt)
        ) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::ProviderAuthorityStale,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
                capability: $capability,
            );
        }

        if (
            $connection->sourceVersion !== $authorization->connectionSourceVersion
            || $this->missingAuthority($connection, $capability)
        ) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::PermissionLost,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
                capability: $capability,
            );
        }

        if ($providerError?->category === ProviderErrorCategory::Authentication) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::CredentialInvalid,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
                capability: $capability,
            );
        }

        if ($providerError?->category === ProviderErrorCategory::Authorization) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::PermissionLost,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
                capability: $capability,
            );
        }

        if ($circuitState === PublicationProviderCircuitState::Open) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::CircuitOpen,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
                capability: $capability,
            );
        }

        if ($circuitState === PublicationProviderCircuitState::HalfOpen) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: PublicationProviderOutcomeCode::CircuitHalfOpen,
                retryEligible: false,
                workHeld: true,
                evaluatedAt: $evaluatedAt,
                capability: $capability,
            );
        }

        $providerOutcome = $this->providerErrorOutcome($providerError);
        if ($providerOutcome !== null) {
            return $this->outcome(
                authorization: $authorization,
                boundaries: $boundaries,
                circuitState: $circuitState,
                providerError: $providerError,
                code: $providerOutcome,
                retryEligible: in_array($providerOutcome, [
                    PublicationProviderOutcomeCode::RateLimited,
                    PublicationProviderOutcomeCode::ProviderRetryable,
                ], true),
                workHeld: $providerOutcome !== PublicationProviderOutcomeCode::ProviderRetryable,
                evaluatedAt: $evaluatedAt,
                capability: $capability,
            );
        }

        return $this->outcome(
            authorization: $authorization,
            boundaries: $boundaries,
            circuitState: $circuitState,
            providerError: null,
            code: PublicationProviderOutcomeCode::Ready,
            retryEligible: false,
            workHeld: false,
            evaluatedAt: $evaluatedAt,
            capability: $capability,
        );
    }

    private function readinessOutcome(ProviderConnection $connection): ?PublicationProviderOutcomeCode
    {
        return match ($connection->readiness) {
            ProviderReadinessStatus::Ready => null,
            ProviderReadinessStatus::AuthRequired => PublicationProviderOutcomeCode::CredentialInvalid,
            ProviderReadinessStatus::ScopeRequired => PublicationProviderOutcomeCode::PermissionLost,
            ProviderReadinessStatus::ProviderReviewRequired => PublicationProviderOutcomeCode::AppReviewRestricted,
            default => PublicationProviderOutcomeCode::ProviderDisconnected,
        };
    }

    private function capabilityDrifted(
        PublicationOperationAuthorization $authorization,
        ProviderCapability $capability,
    ): bool {
        return $capability->workspaceId !== $authorization->workspaceId
            || $capability->providerId !== $authorization->providerId
            || $capability->connectionId !== $authorization->providerConnectionId
            || $capability->operation !== $authorization->providerCapabilityOperation
            || $capability->support !== CapabilitySupport::Supported
            || $capability->id !== $authorization->currentCapabilityEvidenceId
            || $capability->sourceVersion !== $authorization->currentCapabilitySourceVersion;
    }

    private function missingAuthority(ProviderConnection $connection, ProviderCapability $capability): bool
    {
        $requiredScopes = $this->stringList($capability->requiredScopes);
        $requiredRoles = $this->stringList($capability->requiredRoles);
        $grantedScopes = $this->stringList($connection->grantedScopes);
        $roles = $this->stringList($connection->roles);

        return $requiredScopes === null
            || $requiredRoles === null
            || $grantedScopes === null
            || $roles === null
            || array_diff($requiredScopes, $grantedScopes) !== []
            || array_diff($requiredRoles, $roles) !== [];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>|null
     */
    private function stringList(array $values): ?array
    {
        if (! array_is_list($values)) {
            return null;
        }

        $result = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            $result[] = $value;
        }

        return $result;
    }

    private function providerErrorOutcome(?NormalizedProviderError $error): ?PublicationProviderOutcomeCode
    {
        return match ($error?->category) {
            null => null,
            ProviderErrorCategory::RateLimited => PublicationProviderOutcomeCode::RateLimited,
            ProviderErrorCategory::Unavailable => PublicationProviderOutcomeCode::ProviderUnavailable,
            ProviderErrorCategory::Retryable => PublicationProviderOutcomeCode::ProviderRetryable,
            ProviderErrorCategory::Validation,
            ProviderErrorCategory::Permanent => PublicationProviderOutcomeCode::ProviderRejected,
            ProviderErrorCategory::Unknown => PublicationProviderOutcomeCode::ProviderUnknown,
            ProviderErrorCategory::Authentication => PublicationProviderOutcomeCode::CredentialInvalid,
            ProviderErrorCategory::Authorization => PublicationProviderOutcomeCode::PermissionLost,
        };
    }

    private function outcome(
        PublicationOperationAuthorization $authorization,
        PublicationPolicyBoundaryEvidence $boundaries,
        PublicationProviderCircuitState $circuitState,
        ?NormalizedProviderError $providerError,
        PublicationProviderOutcomeCode $code,
        bool $retryEligible,
        bool $workHeld,
        DateTimeImmutable $evaluatedAt,
        ?string $failedPolicyBoundary = null,
        ?ProviderCapability $capability = null,
    ): PublicationProviderOutcome {
        return new PublicationProviderOutcome(
            workspaceId: $authorization->workspaceId,
            publicationAttemptId: $authorization->publicationAttemptId,
            authorizationHash: $authorization->authorizationHash,
            code: $code,
            circuitState: $circuitState,
            policyBoundaryEvidenceHash: $boundaries->evidenceHash,
            failedPolicyBoundary: $failedPolicyBoundary,
            providerErrorCategory: $providerError?->category,
            retryAfterSeconds: $providerError?->retryAfterSeconds,
            currentCapabilityEvidenceId: $capability?->id,
            currentCapabilitySourceVersion: $capability?->sourceVersion,
            retryEligible: $retryEligible,
            workHeld: $workHeld,
            fallbackAllowed: false,
            evaluatedAt: $evaluatedAt,
        );
    }
}
