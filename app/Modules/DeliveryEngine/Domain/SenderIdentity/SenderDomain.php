<?php

namespace App\Modules\DeliveryEngine\Domain\SenderIdentity;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SenderDomain
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public SenderDomainName $domain,
        public SenderDomainLifecycle $lifecycle,
        public string $idempotencyKey,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        foreach (['id' => $this->id, 'workspaceId' => $this->workspaceId, 'idempotencyKey' => $this->idempotencyKey] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name.' must not be empty.');
            }
        }

        if ($this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Sender domain updatedAt must not precede createdAt.');
        }

        SenderRecordGuard::assertNoSecretMaterial($this->metadata, 'metadata');
    }

    public function transitionTo(SenderDomainLifecycle $next, DateTimeImmutable $at): self
    {
        if ($next === $this->lifecycle) {
            return $this;
        }

        if (! $this->lifecycle->canTransitionTo($next)) {
            throw new InvalidArgumentException('Invalid sender domain lifecycle transition from '.$this->lifecycle->value.' to '.$next->value.'.');
        }

        if ($at < $this->updatedAt) {
            throw new InvalidArgumentException('Sender domain transition time must not move backwards.');
        }

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            domain: $this->domain,
            lifecycle: $next,
            idempotencyKey: $this->idempotencyKey,
            metadata: $this->metadata,
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }

    public function identityKey(): string
    {
        return $this->workspaceId.':'.$this->domain->value;
    }
}
