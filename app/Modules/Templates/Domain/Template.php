<?php

namespace App\Modules\Templates\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Template
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $name,
        public TemplateLifecycle $lifecycle,
        public string $createdByActorId,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        foreach (['id' => $id, 'workspaceId' => $workspaceId, 'name' => $name, 'createdByActorId' => $createdByActorId] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Template {$field} must not be empty.");
            }
        }

        if (mb_strlen($name) > 191) {
            throw new InvalidArgumentException('Template name must not exceed 191 characters.');
        }

        if ($updatedAt !== null && $updatedAt < $createdAt) {
            throw new InvalidArgumentException('Template updatedAt must not precede createdAt.');
        }
    }

    public function transitionTo(TemplateLifecycle $next, DateTimeImmutable $at): self
    {
        if ($next === $this->lifecycle) {
            return $this;
        }

        if (! $this->lifecycle->canTransitionTo($next)) {
            throw new InvalidArgumentException("Invalid template lifecycle transition from {$this->lifecycle->value} to {$next->value}.");
        }

        if ($at < ($this->updatedAt ?? $this->createdAt)) {
            throw new InvalidArgumentException('Template lifecycle transition time must not move backwards.');
        }

        return new self($this->id, $this->workspaceId, $this->name, $next, $this->createdByActorId, $this->createdAt, $at);
    }
}
