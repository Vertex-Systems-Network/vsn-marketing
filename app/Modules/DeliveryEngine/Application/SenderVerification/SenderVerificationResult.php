<?php

namespace App\Modules\DeliveryEngine\Application\SenderVerification;

use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationDecision;
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicyDecision;
use DateTimeImmutable;

final readonly class SenderVerificationResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public string $operationKey,
        public string $workspaceId,
        public string $senderDomainId,
        public string $providerKey,
        public SenderVerificationObservationOutcome $observationOutcome,
        public AuthenticationDecision $authentication,
        public MailboxProviderPolicyDecision $providerPolicy,
        public bool $eligibleForLaterSendingEvaluation,
        public bool $productionActivationAllowed,
        public array $reasons,
        public DateTimeImmutable $evaluatedAt,
    ) {}
}
