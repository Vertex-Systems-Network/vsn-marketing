<?php

namespace App\Modules\Providers\Infrastructure\Messaging;

use App\Modules\Providers\Domain\Messaging\Contracts\MessagingOperationRepository;
use App\Modules\Providers\Domain\Messaging\MessagingChannel;
use App\Modules\Providers\Domain\Messaging\MessagingOperation;
use App\Modules\Providers\Domain\Messaging\MessagingOperationState;
use App\Modules\Providers\Domain\Messaging\MessagingProviderOutcome;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class DatabaseMessagingOperationRepository implements MessagingOperationRepository
{
    public function reserve(string $workspaceId, string $channel, string $providerKey, string $idempotencyKey, string $requestFingerprint): MessagingOperation
    {
        if (! Str::isUuid($workspaceId) || MessagingChannel::tryFrom($channel) === null
            || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,119}$/D', $providerKey)
            || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 191
            || ! preg_match('/^[a-f0-9]{64}$/D', $requestFingerprint)) {
            throw new InvalidArgumentException('Invalid messaging reservation identity or fingerprint.');
        }

        return DB::transaction(function () use ($workspaceId, $channel, $providerKey, $idempotencyKey, $requestFingerprint): MessagingOperation {
            $now = new DateTimeImmutable;
            // The unique key arbitrates concurrent first inserts; a missing row cannot be row-locked.
            DB::table('messaging_operations')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
                'channel' => $channel, 'provider_key' => $providerKey,
                'idempotency_key' => $idempotencyKey, 'request_fingerprint' => $requestFingerprint,
                'operation_state' => MessagingOperationState::Reserved->value,
                'provider_operation_id' => null, 'ambiguous_outcome' => false,
                'evidence' => json_encode(['reservation' => 'durable_offline'], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $row = DB::table('messaging_operations')->where('workspace_id', $workspaceId)
                ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Messaging reservation was not persisted.');
            }
            if ((string) $row->request_fingerprint !== $requestFingerprint
                || (string) $row->channel !== $channel || (string) $row->provider_key !== $providerKey) {
                throw new InvalidArgumentException('Idempotency key was reused for a different messaging request.');
            }

            return $this->fromRow($row);
        }, 3);
    }

    public function reconcile(MessagingOperation $operation, MessagingProviderOutcome $outcome): MessagingOperation
    {
        return DB::transaction(function () use ($operation, $outcome): MessagingOperation {
            $row = DB::table('messaging_operations')->where('id', $operation->id)
                ->where('workspace_id', $operation->workspaceId)
                ->where('channel', $operation->channel)->where('provider_key', $operation->providerKey)
                ->where('idempotency_key', $operation->idempotencyKey)
                ->where('request_fingerprint', $operation->requestFingerprint)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Messaging operation was not found in the supplied scope.');
            }
            $current = $this->fromRow($row);
            $next = $current->reconcile($outcome);
            if ($next === $current) {
                return $current;
            }
            DB::table('messaging_operations')->where('id', $next->id)->where('workspace_id', $next->workspaceId)->update([
                'operation_state' => $next->state->value,
                'provider_operation_id' => $next->providerOperationId,
                'ambiguous_outcome' => $next->ambiguousOutcome,
                'evidence' => json_encode($next->evidence, JSON_THROW_ON_ERROR),
                'updated_at' => $next->updatedAt,
            ]);

            return $next;
        }, 3);
    }

    private function fromRow(object $row): MessagingOperation
    {
        return new MessagingOperation(
            (string) $row->id, (string) $row->workspace_id, (string) $row->channel,
            (string) $row->provider_key, (string) $row->idempotency_key, (string) $row->request_fingerprint,
            MessagingOperationState::from((string) $row->operation_state),
            $row->provider_operation_id === null ? null : (string) $row->provider_operation_id,
            (bool) $row->ambiguous_outcome,
            json_decode((string) $row->evidence, true, 512, JSON_THROW_ON_ERROR),
            new DateTimeImmutable((string) $row->created_at), new DateTimeImmutable((string) $row->updated_at),
        );
    }
}
