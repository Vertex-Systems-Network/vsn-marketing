<?php

namespace App\Modules\Journeys\Infrastructure\Persistence;

use App\Modules\Journeys\Domain\Contracts\JourneyWaitRepository;
use App\Modules\Journeys\Domain\DurableJourneyWait;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyWaitRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class DatabaseJourneyWaitRepository implements JourneyWaitRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function store(DurableJourneyWait $wait, ?array $predicate = null): bool
    {
        $connection = $this->database->connection();
        $encodedPredicate = $predicate === null ? null : $this->encodeCanonical($predicate);
        $wakeAt = $wait->wakeAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        $now = now();
        $inserted = $connection->table('journey_waits')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'workspace_id' => $wait->workspaceId,
            'execution_id' => $wait->executionId,
            'node_id' => $wait->nodeId,
            'wait_key' => $wait->idempotencyKey,
            'wake_at' => $wakeAt,
            'predicate' => $encodedPredicate,
            'status' => 'pending',
            'resumed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return true;
        }

        $existing = $connection->table('journey_waits')
            ->where('workspace_id', $wait->workspaceId)
            ->where('wait_key', $wait->idempotencyKey)
            ->first();
        if (! $existing instanceof stdClass) {
            throw new RuntimeException('Journey wait insert was ignored without a matching persisted identity.');
        }

        $storedWakeAt = (new DateTimeImmutable((string) $existing->wake_at, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $storedPredicate = $existing->predicate === null ? null : json_decode((string) $existing->predicate, true, 512, JSON_THROW_ON_ERROR);
        if ((string) $existing->execution_id !== $wait->executionId
            || (string) $existing->node_id !== $wait->nodeId
            || $storedWakeAt != $wait->wakeAt->setTimezone(new DateTimeZone('UTC'))
            || $this->canonical($storedPredicate) !== $this->canonical($predicate)) {
            throw new JourneyDefinitionException('wait_idempotency_conflict', '$.wait.idempotency_key');
        }

        return false;
    }

    public function due(string $workspaceId, DateTimeImmutable $now, int $limit = 100): array
    {
        if (trim($workspaceId) === '') {
            throw new JourneyDefinitionException('invalid_wait_workspace', '$.wait.workspace_id');
        }
        if ($limit <= 0) {
            return [];
        }
        $limit = min(1000, $limit);
        $instant = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');

        return $this->database->connection()->table('journey_waits')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'pending')
            ->where(function ($query) use ($instant): void {
                // Predicate waits must be reevaluated on bounded scheduler passes before their deadline.
                $query->where('wake_at', '<=', $instant)->orWhereNotNull('predicate');
            })
            ->orderBy('wake_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (stdClass $row): JourneyWaitRecord {
                $wakeAt = new DateTimeImmutable((string) $row->wake_at, new DateTimeZone('UTC'));
                $wait = DurableJourneyWait::restore(
                    (string) $row->workspace_id,
                    (string) $row->execution_id,
                    (string) $row->node_id,
                    $wakeAt,
                    (string) $row->wait_key,
                );
                $predicate = $row->predicate === null
                    ? null
                    : json_decode((string) $row->predicate, true, 512, JSON_THROW_ON_ERROR);
                if ($predicate !== null && ! is_array($predicate)) {
                    throw new JourneyDefinitionException('invalid_persisted_wait_predicate', '$.wait.predicate');
                }

                return new JourneyWaitRecord($wait, $predicate);
            })
            ->all();
    }

    public function markResumed(string $workspaceId, string $waitKey, DateTimeImmutable $resumedAt): bool
    {
        if (trim($workspaceId) === '' || trim($waitKey) === '') {
            throw new JourneyDefinitionException('invalid_wait_identity', '$.wait');
        }

        return $this->database->connection()->table('journey_waits')
            ->where('workspace_id', $workspaceId)
            ->where('wait_key', $waitKey)
            ->where('status', 'pending')
            ->update([
                'status' => 'resumed',
                'resumed_at' => $resumedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
                'updated_at' => now(),
            ]) === 1;
    }

    public function cancelPending(string $workspaceId, string $executionId, DateTimeImmutable $cancelledAt): int
    {
        if (trim($workspaceId) === '' || trim($executionId) === '') {
            throw new JourneyDefinitionException('invalid_wait_identity', '$.wait');
        }

        return $this->database->connection()->table('journey_waits')
            ->where('workspace_id', $workspaceId)
            ->where('execution_id', $executionId)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'updated_at' => $cancelledAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            ]);
    }

    /** @param array<string, mixed>|null $value */
    private function encodeCanonical(?array $value): string
    {
        return json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }
}
