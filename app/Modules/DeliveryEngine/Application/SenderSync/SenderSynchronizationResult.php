<?php

namespace App\Modules\DeliveryEngine\Application\SenderSync;

use DateTimeImmutable;

final readonly class SenderSynchronizationResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public string $operationKey,
        public string $workspaceId,
        public string $senderDomainId,
        public string $providerKey,
        public SenderSynchronizationOutcome $providerOutcome,
        public bool $reconciliationRequired,
        public bool $eligibleForLaterSendingEvaluation,
        public bool $productionActivationAllowed,
        public array $reasons,
        public array $publicEvidence,
        public DateTimeImmutable $observedAt,
        public ?string $providerReference,
        public ?string $sourceVersion,
    ) {}
}
