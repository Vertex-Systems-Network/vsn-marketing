<?php

namespace App\Modules\Events\Infrastructure;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Events\Domain\Contracts\EventTypeRepository;
use App\Modules\Events\Domain\EventType;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use stdClass;

final readonly class DatabaseEventTypeRepository implements EventTypeRepository
{
    public function __construct(
        private DatabaseManager $database,
        private IdentifierGenerator $identifiers,
        private Clock $clock,
    ) {}

    public function ensure(string $workspaceId, string $canonicalName, int $schemaVersion): EventType
    {
        $connection = $this->database->connection();
        $existing = $connection->table('event_types')
            ->where('workspace_id', $workspaceId)
            ->where('canonical_name', $canonicalName)
            ->where('schema_version', $schemaVersion)
            ->first();

        if ($existing instanceof stdClass) {
            return $this->hydrate($existing);
        }

        $id = $this->identifiers->next();
        $connection->table('event_types')->insertOrIgnore([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'canonical_name' => $canonicalName,
            'schema_version' => $schemaVersion,
            'created_at' => $this->clock->now(),
        ]);

        $row = $connection->table('event_types')
            ->where('workspace_id', $workspaceId)
            ->where('canonical_name', $canonicalName)
            ->where('schema_version', $schemaVersion)
            ->first();

        if (! $row instanceof stdClass) {
            throw new RuntimeException('Unable to persist canonical event type.');
        }

        return $this->hydrate($row);
    }

    private function hydrate(stdClass $row): EventType
    {
        return new EventType(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            canonicalName: (string) $row->canonical_name,
            schemaVersion: (int) $row->schema_version,
        );
    }
}
