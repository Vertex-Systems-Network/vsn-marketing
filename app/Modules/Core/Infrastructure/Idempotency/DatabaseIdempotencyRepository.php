<?php

namespace App\Modules\Core\Infrastructure\Idempotency;

use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdempotencyRepository;
use App\Modules\Core\Domain\Idempotency\IdempotencyClaim;
use Illuminate\Database\DatabaseManager;
use JsonException;
use RuntimeException;

final readonly class DatabaseIdempotencyRepository implements IdempotencyRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {
    }

    public function claim(string $workspaceId, string $scope, string $key): IdempotencyClaim
    {
        $now = $this->clock->now();
        $inserted = $this->database->connection()->table('idempotency_keys')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'scope' => $scope,
            'idempotency_key' => $key,
            'status' => 'processing',
            'result' => null,
            'attempts' => 1,
            'last_error' => null,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return new IdempotencyClaim(IdempotencyClaim::ACQUIRED);
        }

        return $this->database->connection()->transaction(function () use ($workspaceId, $scope, $key): IdempotencyClaim {
            $query = $this->database->connection()->table('idempotency_keys')
                ->where('workspace_id', $workspaceId)
                ->where('scope', $scope)
                ->where('idempotency_key', $key);
            $row = $query->lockForUpdate()->first();

            if ($row === null) {
                throw new RuntimeException('Idempotency claim disappeared during conflict resolution.');
            }

            if ($row->status === 'completed') {
                return new IdempotencyClaim(IdempotencyClaim::COMPLETED, $this->decodeResult($row->result));
            }

            if ($row->status === 'processing') {
                return new IdempotencyClaim(IdempotencyClaim::IN_PROGRESS);
            }

            if ($row->status !== 'failed') {
                throw new RuntimeException("Unknown idempotency status: {$row->status}");
            }

            $query->update([
                'status' => 'processing',
                'attempts' => (int) $row->attempts + 1,
                'last_error' => null,
                'updated_at' => $this->clock->now(),
            ]);

            return new IdempotencyClaim(IdempotencyClaim::ACQUIRED);
        });
    }

    public function complete(string $workspaceId, string $scope, string $key, array $result): void
    {
        $now = $this->clock->now();
        $updated = $this->database->connection()->table('idempotency_keys')
            ->where('workspace_id', $workspaceId)
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->where('status', 'processing')
            ->update([
                'status' => 'completed',
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'last_error' => null,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            throw new RuntimeException('Cannot complete an idempotency key that is not processing.');
        }
    }

    public function fail(string $workspaceId, string $scope, string $key, string $error): void
    {
        $updated = $this->database->connection()->table('idempotency_keys')
            ->where('workspace_id', $workspaceId)
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'last_error' => mb_substr($error, 0, 2000),
                'updated_at' => $this->clock->now(),
            ]);

        if ($updated !== 1) {
            throw new RuntimeException('Cannot fail an idempotency key that is not processing.');
        }
    }

    /**
     * @throws JsonException
     */
    private function decodeResult(mixed $result): array
    {
        if ($result === null) {
            return [];
        }

        $decoded = json_decode((string) $result, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
