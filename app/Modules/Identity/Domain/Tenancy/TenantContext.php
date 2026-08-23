<?php

namespace App\Modules\Identity\Domain\Tenancy;

use InvalidArgumentException;
use JsonSerializable;

final readonly class TenantContext implements JsonSerializable
{
    public function __construct(
        public string $organizationId,
        public string $workspaceId,
        public ?string $brandId,
        public string $actorId,
    ) {
        foreach (['organizationId', 'workspaceId', 'actorId'] as $field) {
            if ($this->{$field} === '') {
                throw new InvalidArgumentException("Tenant context {$field} cannot be empty.");
            }
        }
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'workspace_id' => $this->workspaceId,
            'brand_id' => $this->brandId,
            'actor_id' => $this->actorId,
        ];
    }

    public function eventMetadata(): array
    {
        return ['tenant' => $this->toArray()];
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            organizationId: (string) ($payload['organization_id'] ?? ''),
            workspaceId: (string) ($payload['workspace_id'] ?? ''),
            brandId: isset($payload['brand_id']) ? (string) $payload['brand_id'] : null,
            actorId: (string) ($payload['actor_id'] ?? ''),
        );
    }

    public static function fromEventMetadata(array $metadata): self
    {
        $tenant = $metadata['tenant'] ?? null;

        if (! is_array($tenant)) {
            throw new InvalidArgumentException('Canonical event metadata is missing tenant context.');
        }

        return self::fromArray($tenant);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
