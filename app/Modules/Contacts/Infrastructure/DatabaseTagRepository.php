<?php

namespace App\Modules\Contacts\Infrastructure;

use App\Modules\Contacts\Domain\Contracts\TagRepository;
use App\Modules\Contacts\Domain\Tag;
use App\Modules\Core\Domain\Contracts\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class DatabaseTagRepository implements TagRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function create(string $id, string $workspaceId, string $name): Tag
    {
        $now = $this->clock->now();

        $this->database->connection()->table('tags')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new Tag($id, $workspaceId, $name);
    }

    public function find(string $workspaceId, string $tagId): ?Tag
    {
        $row = $this->database->connection()->table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('id', $tagId)
            ->first();

        return $row instanceof stdClass
            ? new Tag((string) $row->id, (string) $row->workspace_id, (string) $row->name)
            : null;
    }

    public function assignContact(string $workspaceId, string $tagId, string $contactId): bool
    {
        $this->assertTagExists($workspaceId, $tagId);
        $this->assertContactExists($workspaceId, $contactId);

        $inserted = $this->database->connection()->table('contact_tag_assignments')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'tag_id' => $tagId,
            'contact_id' => $contactId,
            'created_at' => $this->clock->now(),
        ]);

        return $inserted === 1;
    }

    public function unassignContact(string $workspaceId, string $tagId, string $contactId): bool
    {
        $this->assertTagExists($workspaceId, $tagId);
        $this->assertContactExists($workspaceId, $contactId);

        return $this->database->connection()->table('contact_tag_assignments')
            ->where('workspace_id', $workspaceId)
            ->where('tag_id', $tagId)
            ->where('contact_id', $contactId)
            ->delete() === 1;
    }

    private function assertTagExists(string $workspaceId, string $tagId): void
    {
        if ($this->find($workspaceId, $tagId) === null) {
            throw new AuthorizationException('Tag access denied.');
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
