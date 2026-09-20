<?php

namespace App\Modules\Content\Domain\Brand;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BrandKit
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $name,
        public string $createdByActorId,
        public DateTimeImmutable $createdAt,
    ) {
        foreach ([
            'id' => $this->id,
            'workspaceId' => $this->workspaceId,
            'name' => $this->name,
            'createdByActorId' => $this->createdByActorId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Brand kit {$field} must not be empty.");
            }
        }

        if (mb_strlen($this->name) > 191) {
            throw new InvalidArgumentException('Brand kit name must not exceed 191 characters.');
        }
    }
}
