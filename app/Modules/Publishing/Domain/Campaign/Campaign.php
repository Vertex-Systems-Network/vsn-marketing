<?php

namespace App\Modules\Publishing\Domain\Campaign;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Campaign
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $name,
        public CampaignStatus $status,
        public int $stateVersion,
        public string $idempotencyKey,
        public string $createdByActorId,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->name, 'name');
        CampaignPayloadGuard::assertIdentifier($this->idempotencyKey, 'idempotencyKey');
        CampaignPayloadGuard::assertIdentifier($this->createdByActorId, 'createdByActorId');

        if ($this->stateVersion < 1) {
            throw new InvalidArgumentException('Campaign stateVersion must be at least 1.');
        }

        if ($this->stateVersion === 1 && $this->status !== CampaignStatus::Draft) {
            throw new InvalidArgumentException('A new campaign must begin in draft status.');
        }

        if ($this->updatedAt !== null && $this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Campaign updatedAt must not precede createdAt.');
        }
    }

    public static function draft(
        string $id,
        string $workspaceId,
        string $name,
        string $idempotencyKey,
        string $createdByActorId,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            workspaceId: $workspaceId,
            name: $name,
            status: CampaignStatus::Draft,
            stateVersion: 1,
            idempotencyKey: $idempotencyKey,
            createdByActorId: $createdByActorId,
            createdAt: $createdAt,
        );
    }

    public function transitionTo(CampaignStatus $next, DateTimeImmutable $at): self
    {
        if ($next === $this->status) {
            return $this;
        }

        if (! $this->status->canTransitionTo($next)) {
            throw new InvalidArgumentException("Invalid campaign lifecycle transition from {$this->status->value} to {$next->value}.");
        }

        $lastChangedAt = $this->updatedAt ?? $this->createdAt;
        if ($at < $lastChangedAt) {
            throw new InvalidArgumentException('Campaign lifecycle transition time must not move backwards.');
        }

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            name: $this->name,
            status: $next,
            stateVersion: $this->stateVersion + 1,
            idempotencyKey: $this->idempotencyKey,
            createdByActorId: $this->createdByActorId,
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }
}
