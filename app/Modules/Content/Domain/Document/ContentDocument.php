<?php

namespace App\Modules\Content\Domain\Document;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ContentDocument
{
    /** @param array<string, mixed> $auditProvenance */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $name,
        public ContentLifecycle $lifecycle,
        public string $createdByActorId,
        public array $auditProvenance,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        foreach (['id' => $this->id, 'workspaceId' => $this->workspaceId, 'name' => $this->name, 'createdByActorId' => $this->createdByActorId] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Content document {$field} must not be empty.");
            }
        }

        if (mb_strlen($this->name) > 191) {
            throw new InvalidArgumentException('Content document name must not exceed 191 characters.');
        }

        if ($this->updatedAt !== null && $this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Content document updatedAt must not precede createdAt.');
        }

        self::assertPublicProvenance($this->auditProvenance, 'auditProvenance');
    }

    public function transitionTo(ContentLifecycle $next, DateTimeImmutable $at): self
    {
        if ($next === $this->lifecycle) {
            return $this;
        }

        if (! $this->lifecycle->canTransitionTo($next)) {
            throw new InvalidArgumentException("Invalid content document lifecycle transition from {$this->lifecycle->value} to {$next->value}.");
        }

        $lastUpdatedAt = $this->updatedAt ?? $this->createdAt;
        if ($at < $lastUpdatedAt) {
            throw new InvalidArgumentException('Content document lifecycle transition time must not move backwards.');
        }

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            name: $this->name,
            lifecycle: $next,
            createdByActorId: $this->createdByActorId,
            auditProvenance: $this->auditProvenance,
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }

    /** @param array<string, mixed> $value */
    private static function assertPublicProvenance(array $value, string $path): void
    {
        foreach ($value as $key => $nested) {
            if (is_string($key) && preg_match('/password|secret|token|authorization|credential|api[_-]?key|private[_-]?key/i', $key)) {
                throw new InvalidArgumentException("Sensitive content provenance key is forbidden: {$path}.{$key}");
            }

            if (is_array($nested)) {
                self::assertPublicProvenance($nested, $path.'.'.(string) $key);
            }
        }
    }
}
