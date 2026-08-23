<?php

namespace App\Modules\Contacts\Domain;

final readonly class Company
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $brandId,
        public string $name,
        public ?string $domain,
    ) {}
}
