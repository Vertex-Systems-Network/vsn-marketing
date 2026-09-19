<?php

namespace App\Modules\Assets\Domain\Asset;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CanonicalAsset
{
    /** @param array<string, mixed> $auditProvenance */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $name,
        public AssetKind $kind,
        public AssetLifecycle $lifecycle,
        public string $createdByActorId,
        public array $auditProvenance,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        foreach (['id' => $this->id, 'workspaceId' => $this->workspaceId, 'name' => $this->name, 'createdByActorId' => $this->createdByActorId] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Canonical asset {$field} must not be empty.");
            }
        }

        if (mb_strlen($this->name) > 191) {
            throw new InvalidArgumentException('Canonical asset name must not exceed 191 characters.');
        }

        if ($this->updatedAt !== null && $this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Canonical asset updatedAt must not precede createdAt.');
        }

        self::assertPublicMetadata($this->auditProvenance, 'auditProvenance');
    }

    public function transitionTo(AssetLifecycle $next, DateTimeImmutable $at): self
    {
        if ($next === $this->lifecycle) {
            return $this;
        }

        if (! $this->lifecycle->canTransitionTo($next)) {
            throw new InvalidArgumentException("Invalid canonical asset lifecycle transition from {$this->lifecycle->value} to {$next->value}.");
        }

        $lastUpdatedAt = $this->updatedAt ?? $this->createdAt;
        if ($at < $lastUpdatedAt) {
            throw new InvalidArgumentException('Canonical asset lifecycle transition time must not move backwards.');
        }

        return new self(
            id: $this->id,
            workspaceId: $this->workspaceId,
            name: $this->name,
            kind: $this->kind,
            lifecycle: $next,
            createdByActorId: $this->createdByActorId,
            auditProvenance: $this->auditProvenance,
            createdAt: $this->createdAt,
            updatedAt: $at,
        );
    }

    /** @param array<string, mixed> $value */
    public static function assertPublicMetadata(array $value, string $path): void
    {
        foreach ($value as $key => $nested) {
            if (is_string($key) && preg_match('/password|secret|token|authorization|credential|api[_-]?key|private[_-]?key/i', $key)) {
                throw new InvalidArgumentException("Sensitive asset metadata key is forbidden: {$path}.{$key}");
            }

            if (is_array($nested)) {
                self::assertPublicMetadata($nested, $path.'.'.(string) $key);
            }
        }
    }
}
