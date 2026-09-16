<?php

namespace App\Modules\DeliveryEngine\Application\SenderVerification;

use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationEvidence;
use App\Modules\Providers\Domain\SenderPolicy\MailboxProviderPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SenderVerificationRequest
{
    /**
     * @param  list<AuthenticationEvidence>  $authenticationEvidence
     * @param  list<MailboxProviderPolicy>  $providerPolicies
     */
    public function __construct(
        public string $operationKey,
        public string $workspaceId,
        public string $senderDomainId,
        public string $providerKey,
        public array $authenticationEvidence,
        public array $providerPolicies,
        public DateTimeImmutable $evaluatedAt,
        public SenderVerificationObservationOutcome $observationOutcome = SenderVerificationObservationOutcome::Completed,
        public ?int $observedVolume = null,
        public ?bool $configuredHighVolume = null,
    ) {
        if (trim($this->operationKey) === '') {
            throw new InvalidArgumentException('Sender verification operation key is required.');
        }

        if (trim($this->workspaceId) === '' || trim($this->senderDomainId) === '') {
            throw new InvalidArgumentException('Workspace and sender-domain IDs are required for sender verification.');
        }

        if (trim($this->providerKey) === '') {
            throw new InvalidArgumentException('Provider key is required for sender verification.');
        }

        if ($this->observedVolume !== null && $this->observedVolume < 0) {
            throw new InvalidArgumentException('Observed provider volume must be non-negative when supplied.');
        }
    }
}
