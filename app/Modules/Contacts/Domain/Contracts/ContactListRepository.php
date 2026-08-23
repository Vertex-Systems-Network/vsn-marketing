<?php

namespace App\Modules\Contacts\Domain\Contracts;

use App\Modules\Contacts\Domain\ContactList;

interface ContactListRepository
{
    public function create(string $id, string $workspaceId, string $name): ContactList;

    public function find(string $workspaceId, string $listId): ?ContactList;

    public function addContact(string $workspaceId, string $listId, string $contactId): bool;

    public function removeContact(string $workspaceId, string $listId, string $contactId): bool;
}
