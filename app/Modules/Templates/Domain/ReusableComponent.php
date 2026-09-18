<?php

namespace App\Modules\Templates\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReusableComponent
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $name,
        public ComponentLifecycle $lifecycle,
        public string $createdByActorId,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        foreach (['id' => $id, 'workspaceId' => $workspaceId, 'name' => $name, 'createdByActorId' => $createdByActorId] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Reusable component {$field} must not be empty.");
            }
        }

        if (mb_strlen($name) > 191) {
            throw new InvalidArgumentException('Reusable component name must not exceed 191 characters.');
        }

        if ($updatedAt !== null && $updatedAt < $createdAt) {
            throw new InvalidArgumentException('Reusable component updatedAt must not precede createdAt.');
        }
    }

    public function transitionTo(ComponentLifecycle $next, DateTimeImmutable $at): self
    {
        if ($next === $this->lifecycle) {
            return $this;
        }

        if ($this->lifecycle->canTransitionTo($next) === false) {
            throw new InvalidArgumentException("Invalid reusable component lifecycle transition from {$this->lifecycle->value} to {$next->value}.");
        }

        if ($at < ($this->updatedAt ?? $this->createdAt)) {
            throw new InvalidArgumentException('Reusable component lifecycle transition time must not move backwards.');
        }

        return new self($this->id, $this->workspaceId, $this->name, $next, $this->createdByActorId, $this->createdAt, $at);
    }
}
