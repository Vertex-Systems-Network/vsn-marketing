<?php

namespace App\Modules\Contacts\Infrastructure;

use App\Modules\Contacts\Domain\ContactList;
use App\Modules\Contacts\Domain\Contracts\ContactListRepository;
use App\Modules\Core\Domain\Contracts\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class DatabaseContactListRepository implements ContactListRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function create(string $id, string $workspaceId, string $name): ContactList
    {
        $now = $this->clock->now();

        $this->database->connection()->table('contact_lists')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new ContactList($id, $workspaceId, $name);
    }

    public function find(string $workspaceId, string $listId): ?ContactList
    {
        $row = $this->database->connection()->table('contact_lists')
            ->where('workspace_id', $workspaceId)
            ->where('id', $listId)
            ->first();

        return $row instanceof stdClass
            ? new ContactList((string) $row->id, (string) $row->workspace_id, (string) $row->name)
            : null;
    }

    public function addContact(string $workspaceId, string $listId, string $contactId): bool
    {
        $this->assertListExists($workspaceId, $listId);
        $this->assertContactExists($workspaceId, $contactId);

        $inserted = $this->database->connection()->table('contact_list_memberships')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'list_id' => $listId,
            'contact_id' => $contactId,
            'created_at' => $this->clock->now(),
        ]);

        return $inserted === 1;
    }

    public function removeContact(string $workspaceId, string $listId, string $contactId): bool
    {
        $this->assertListExists($workspaceId, $listId);
        $this->assertContactExists($workspaceId, $contactId);

        return $this->database->connection()->table('contact_list_memberships')
            ->where('workspace_id', $workspaceId)
            ->where('list_id', $listId)
            ->where('contact_id', $contactId)
            ->delete() === 1;
    }

    private function assertListExists(string $workspaceId, string $listId): void
    {
        if ($this->find($workspaceId, $listId) === null) {
            throw new AuthorizationException('Contact list access denied.');
        }
    }

    private function assertContactExists(string $workspaceId, string $contactId): void
    {
        $exists = $this->database->connection()->table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('id', $contactId)
            ->exists();

        if (! $exists) {
            throw new AuthorizationException('Contact access denied.');
        }
    }
}
