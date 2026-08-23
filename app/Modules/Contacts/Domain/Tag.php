<?php

namespace App\Modules\Contacts\Domain;

final readonly class Tag
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $name,
    ) {}
}
