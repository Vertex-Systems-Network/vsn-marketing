<?php

namespace App\Modules\DeliveryEngine\Domain\SenderIdentity;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SenderIdentity
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $senderDomainId,
        public SenderDomainName $domain,
        public string $localPart,
        public ?string $displayName,
        public ?string $replyToAddress,
        public SenderIdentityLifecycle $lifecycle,
        public SenderEligibilityContext $eligibility,
        public ?string $providerConnectionId,
        public string $idempotencyKey,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'senderDomainId' => $this->senderDomainId,
            'localPart' => $this->localPart,
            'idempotencyKey' => $this->idempotencyKey,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name.' must not be empty.');
            }
        }

        if ($this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Sender identity updatedAt must not precede createdAt.');
        }

        if (filter_var($this->emailAddress(), FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Sender identity must produce a valid email address.');
        }

        if ($this->replyToAddress !== null && filter_var($this->replyToAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Reply-to address must be a valid email address when supplied.');
        }

        if ($this->providerConnectionId !== null && trim($this->providerConnectionId) === '') {
            throw new InvalidArgumentException('Provider connection ID must not be blank when supplied.');
        }

        if ($this->providerConnectionId !== null && $this->eligibility->providerKey === null) {
            throw new InvalidArgumentException('Provider context is required when a provider connection is supplied.');
        }

        SenderRecordGuard::assertNoSecretMaterial($this->metadata, 'metadata');
    }

    public function emailAddress(): string
    {
        return $this->localPart.'@'.$this->domain->value;
    }

    public function identityKey(): string
    {
        return $this->workspaceId.':'.strtolower($this->emailAddress());
    }

    public function transitionTo(SenderIdentityLifecycle $next, DateTimeImmutable $at): self
    {
        if ($next === $this->lifecycle) {
            return $this;
        }

        if (! $this->lifecycle->canTransitionTo($next)) {
            throw new InvalidArgumentException('Invalid sender identity lifecycle transition from '.$this->lifecycle->value.' to '.$next->value.'.');
        }

        return $this->copy(lifecycle: $next, eligibility: $this->eligibility, at: $at);
    }

    public function withEligibility(SenderEligibilityContext $eligibility, DateTimeImmutable $at): self
    {
        if ($eligibility == $this->eligibility) {
            return $this;
        }

        return $this->copy(lifecycle: $this->lifecycle, eligibility: $eligibility, at: $at);
    }

    private function copy(SenderIdentityLifecycle $lifecycle, SenderEligibilityContext $eligibility, DateTimeImmutable $at): self
    {
        if ($at < $this->updatedAt) {
            throw new InvalidArgumentException('Sender identity update time must not move backwards.');
        }

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            senderDomainId: $this->senderDomainId,
            domain: $this->domain,
            localPart: $this->localPart,
            displayName: $this->displayName,
            replyToAddress: $this->replyToAddress,
            lifecycle: $lifecycle,
            eligibility: $eligibility,
            providerConnectionId: $this->providerConnectionId,
            idempotencyKey: $this->idempotencyKey,
            metadata: $this->metadata,
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }
}
