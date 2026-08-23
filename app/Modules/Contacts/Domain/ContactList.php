<?php

namespace App\Modules\Contacts\Domain;

final readonly class ContactList
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $name,
    ) {}
}
