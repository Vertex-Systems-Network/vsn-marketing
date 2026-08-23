<?php

namespace App\Modules\Contacts\Domain;

final readonly class Contact
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $brandId,
        public ?string $companyId,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $displayName,
    ) {}
}
