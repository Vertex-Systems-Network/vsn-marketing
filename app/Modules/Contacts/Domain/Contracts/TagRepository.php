<?php

namespace App\Modules\Contacts\Domain\Contracts;

use App\Modules\Contacts\Domain\Tag;

interface TagRepository
{
    public function create(string $id, string $workspaceId, string $name): Tag;

    public function find(string $workspaceId, string $tagId): ?Tag;

    public function assignContact(string $workspaceId, string $tagId, string $contactId): bool;

    public function unassignContact(string $workspaceId, string $tagId, string $contactId): bool;
}
