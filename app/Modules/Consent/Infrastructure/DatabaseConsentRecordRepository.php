<?php

namespace App\Modules\Consent\Infrastructure;

use App\Modules\Consent\Domain\ConsentDecision;
use App\Modules\Consent\Domain\ConsentRecord;
use App\Modules\Consent\Domain\Contracts\ConsentRecordRepository;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class DatabaseConsentRecordRepository implements ConsentRecordRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function append(ConsentRecord $record): void
    {
        $this->database->connection()->table('consent_records')->insert([
            'id' => $record->id,
            'workspace_id' => $record->workspaceId,
            'contact_id' => $record->contactId,
            'channel' => $record->channel,
            'purpose' => $record->purpose,
            'source' => $record->source,
            'decision' => $record->decision->value,
            'occurred_at' => $record->occurredAt,
            'created_at' => $record->occurredAt,
        ]);
    }

    public function latestFor(
        string $workspaceId,
        string $contactId,
        string $channel,
        string $purpose,
    ): array {
        $connection = $this->database->connection();
        $latestOccurredAt = $connection->table('consent_records')
            ->where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->max('occurred_at');

        if ($latestOccurredAt === null) {
            return [];
        }

        return $connection->table('consent_records')
            ->where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('occurred_at', $latestOccurredAt)
            ->orderByDesc('id')
            ->get()
            ->map(fn (stdClass $row): ConsentRecord => $this->hydrate($row))
            ->all();
    }

    private function hydrate(stdClass $row): ConsentRecord
    {
        return new ConsentRecord(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            contactId: (string) $row->contact_id,
            channel: (string) $row->channel,
            purpose: (string) $row->purpose,
            source: (string) $row->source,
            decision: ConsentDecision::from((string) $row->decision),
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
        );
    }
}
