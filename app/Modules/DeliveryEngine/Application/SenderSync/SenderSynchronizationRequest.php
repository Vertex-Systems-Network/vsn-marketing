<?php

namespace App\Modules\DeliveryEngine\Application\SenderSync;

use App\Modules\DeliveryEngine\Application\SenderVerification\SenderVerificationResult;
use App\Modules\DeliveryEngine\Domain\SenderIdentity\SenderRecordGuard;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SenderSynchronizationRequest
{
    public function __construct(
        public string $operationKey,
        public string $workspaceId,
        public string $senderDomainId,
        public string $providerKey,
        public SenderVerificationResult $verification,
        public SenderSynchronizationOutcome $providerOutcome,
        public array $publicEvidence,
        public DateTimeImmutable $observedAt,
        public ?string $providerReference = null,
        public ?string $sourceVersion = null,
    ) {
        foreach ([
            'operationKey' => $this->operationKey,
            'workspaceId' => $this->workspaceId,
            'senderDomainId' => $this->senderDomainId,
            'providerKey' => $this->providerKey,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name.' is required for sender synchronization.');
            }
        }

        if (
            $this->verification->workspaceId !== $this->workspaceId
            || $this->verification->senderDomainId !== $this->senderDomainId
            || $this->verification->providerKey !== $this->providerKey
        ) {
            throw new InvalidArgumentException('Sender synchronization must use verification from the same workspace, sender domain, and provider.');
        }

        if ($this->verification->productionActivationAllowed) {
            throw new InvalidArgumentException('Sender synchronization cannot accept verification that already grants production activation.');
        }

        if ($this->providerReference !== null) {
            if (trim($this->providerReference) === '') {
                throw new InvalidArgumentException('Provider reference must not be blank when supplied.');
            }

            if (mb_strlen($this->providerReference) > 191) {
                throw new InvalidArgumentException('Provider reference must not exceed 191 characters.');
            }
        }

        if ($this->sourceVersion !== null) {
            if (trim($this->sourceVersion) === '') {
                throw new InvalidArgumentException('Synchronization source version must not be blank when supplied.');
            }

            if (mb_strlen($this->sourceVersion) > 120) {
                throw new InvalidArgumentException('Synchronization source version must not exceed 120 characters.');
            }
        }

        SenderRecordGuard::assertNoSecretMaterial($this->publicEvidence, 'synchronization_public_evidence');
    }
}
