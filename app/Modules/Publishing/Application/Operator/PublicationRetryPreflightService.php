<?php

namespace App\Modules\Publishing\Application\Operator;

use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use App\Modules\Publishing\Application\Publication\PublicationOperationAuthorizationService;
use App\Modules\Publishing\Domain\Campaign\CampaignPayloadGuard;
use App\Modules\Publishing\Domain\Publication\PublicationAttemptState;
use App\Modules\Publishing\Domain\Publication\PublicationOperation;
use App\Modules\Publishing\Domain\Publication\PublicationOperationAuthorization;
use App\Modules\Publishing\Infrastructure\Persistence\DatabasePublicationAttemptRepository;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class PublicationRetryPreflightService
{
    private const MAX_CANDIDATES = 100;

    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private DatabasePublicationAttemptRepository $attempts,
        private PublicationOperationAuthorizationService $operationAuthorizations,
    ) {}

    /**
     * @param  list<string>  $publicationAttemptIds
     * @return array<string, mixed>
     */
    public function preflight(
        User $actor,
        TenantContext $context,
        array $publicationAttemptIds,
        DateTimeImmutable $at,
    ): array {
        $this->assertSendAuthority($actor, $context);
        $candidates = $this->normalizeCandidates($publicationAttemptIds);

        $eligible = [];
        $excluded = [];
        $bindingItems = [];

        foreach ($candidates as $attemptId) {
            $attempt = $this->attempts->find($context->workspaceId, $attemptId);
            if ($attempt === null) {
                $item = [
                    'publication_attempt_id' => $attemptId,
                    'reason' => 'attempt_not_found',
                ];
                $excluded[] = $item;
                $bindingItems[] = $item;

                continue;
            }

            if ($attempt->state === PublicationAttemptState::Published) {
                $item = [
                    'publication_attempt_id' => $attempt->id,
                    'attempt_hash' => $attempt->attemptHash,
                    'reason' => 'already_successful',
                ];
                $excluded[] = $item;
                $bindingItems[] = $item;

                continue;
            }

            if ($attempt->state !== PublicationAttemptState::FailedRetriable) {
                $item = [
                    'publication_attempt_id' => $attempt->id,
                    'attempt_hash' => $attempt->attemptHash,
                    'reason' => 'not_retriable',
                ];
                $excluded[] = $item;
                $bindingItems[] = $item;

                continue;
            }

            try {
                $authorization = $this->operationAuthorizations->authorize(
                    workspaceId: $context->workspaceId,
                    publicationAttemptId: $attempt->id,
                    operation: PublicationOperation::Retry,
                    at: $at,
                );
            } catch (InvalidArgumentException) {
                $item = [
                    'publication_attempt_id' => $attempt->id,
                    'attempt_hash' => $attempt->attemptHash,
                    'reason' => 'current_authority_blocked',
                ];
                $excluded[] = $item;
                $bindingItems[] = $item;

                continue;
            }

            $eligible[] = $this->safeEligibleItem($authorization);
            $bindingItems[] = $this->bindingItem($authorization);
        }

        $excludedSuccessful = count(array_filter(
            $excluded,
            static fn (array $item): bool => $item['reason'] === 'already_successful',
        ));

        return [
            'confirmation_required' => true,
            'provider_side_effect_executed' => false,
            'confirmation_hash' => CampaignPayloadGuard::hash([
                'operation' => 'publication.retry.preflight',
                'workspace_id' => $context->workspaceId,
                'actor_id' => $context->actorId,
                'candidates' => $candidates,
                'items' => $bindingItems,
            ]),
            'counts' => [
                'candidate' => count($candidates),
                'eligible' => count($eligible),
                'excluded' => count($excluded),
                'excluded_successful' => $excludedSuccessful,
            ],
            'eligible' => $eligible,
            'excluded' => $excluded,
        ];
    }

    private function assertSendAuthority(User $actor, TenantContext $context): void
    {
        if (! $this->authorizer->allows($actor, $context, PermissionCatalog::CAMPAIGN_SEND)) {
            throw new AuthorizationException('Publication retry requires campaign.send permission.');
        }
    }

    /**
     * @param  list<string>  $publicationAttemptIds
     * @return list<string>
     */
    private function normalizeCandidates(array $publicationAttemptIds): array
    {
        if (! array_is_list($publicationAttemptIds) || $publicationAttemptIds === []) {
            throw new InvalidArgumentException('Publication retry requires a non-empty candidate list.');
        }

        if (count($publicationAttemptIds) > self::MAX_CANDIDATES) {
            throw new InvalidArgumentException('Publication retry candidate count exceeds the bounded batch limit.');
        }

        $seen = [];
        foreach ($publicationAttemptIds as $attemptId) {
            if (! is_string($attemptId)) {
                throw new InvalidArgumentException('Publication retry candidate IDs must be strings.');
            }

            CampaignPayloadGuard::assertIdentifier($attemptId, 'publicationRetry.publicationAttemptId');
            if (isset($seen[$attemptId])) {
                throw new InvalidArgumentException('Publication retry candidate IDs must be unique.');
            }

            $seen[$attemptId] = true;
        }

        $candidates = array_keys($seen);
        sort($candidates, SORT_STRING);

        return $candidates;
    }

    /** @return array<string, mixed> */
    private function safeEligibleItem(PublicationOperationAuthorization $authorization): array
    {
        return [
            'publication_attempt_id' => $authorization->publicationAttemptId,
            'attempt_hash' => $authorization->attemptHash,
            'projection_observation_hash' => $authorization->projectionObservationHash,
            'projection_version' => $authorization->projectionVersion,
            'authorization_hash' => $authorization->authorizationHash,
        ];
    }

    /** @return array<string, mixed> */
    private function bindingItem(PublicationOperationAuthorization $authorization): array
    {
        return [
            'publication_attempt_id' => $authorization->publicationAttemptId,
            'attempt_hash' => $authorization->attemptHash,
            'projection_observation_hash' => $authorization->projectionObservationHash,
            'projection_version' => $authorization->projectionVersion,
            'current_capability_evidence_id' => $authorization->currentCapabilityEvidenceId,
            'current_capability_source_version' => $authorization->currentCapabilitySourceVersion,
            'connection_source_version' => $authorization->connectionSourceVersion,
        ];
    }
}
