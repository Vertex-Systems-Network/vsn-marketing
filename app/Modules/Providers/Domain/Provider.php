<?php

namespace App\Modules\Providers\Domain;

use DateTimeImmutable;

final readonly class Provider
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $key,
        public string $displayName,
        public ?string $category,
        public array $metadata,
        public string $sourceUrl,
        public ?string $sourceVersion,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $freshUntil,
    ) {}
}
