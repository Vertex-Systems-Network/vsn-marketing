<?php

namespace App\Modules\Identity\Application\Recovery;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/** Synthetic offline exercise only; this is not a production backup adapter. */
final class OfflineRecoveryExercise
{
    /** @param array<string, mixed> $fixture */
    public function seal(string $workspaceId, array $fixture): string
    {
        if ($workspaceId === '' || ! DB::table('workspaces')->where('id', $workspaceId)->exists()) {
            throw new AuthorizationException('Recovery workspace denied.');
        }

        $body = json_encode(['schema' => 1, 'workspace_id' => $workspaceId, 'fixture' => $fixture], JSON_THROW_ON_ERROR);

        return Crypt::encryptString(json_encode([
            'body' => $body,
            'digest' => hash('sha256', $body),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Verifies integrity and tenant scope, then records measured local restore
     * time. No production data is changed or restored by this exercise.
     *
     * @return array<string, mixed>
     */
    public function rehearse(string $workspaceId, string $sealed, int $expectedItems): array
    {
        $start = hrtime(true);
        if ($workspaceId === '' || $sealed === '' || $expectedItems < 0) {
            throw new AuthorizationException('Recovery exercise denied.');
        }

        try {
            $envelope = json_decode(Crypt::decryptString($sealed), true, 512, JSON_THROW_ON_ERROR);
            $body = $envelope['body'] ?? null;
            $digest = $envelope['digest'] ?? null;
            if (! is_string($body) || ! is_string($digest) || ! hash_equals(hash('sha256', $body), $digest)) {
                throw new AuthorizationException('Recovery integrity check failed.');
            }
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $exception) {
            throw new AuthorizationException('Recovery integrity check failed.', previous: $exception);
        }

        if (! is_array($payload) || ($payload['schema'] ?? null) !== 1
            || ($payload['workspace_id'] ?? null) !== $workspaceId || ! is_array($payload['fixture'] ?? null)
            || count($payload['fixture']) !== $expectedItems) {
            throw new AuthorizationException('Recovery scope or completeness check failed.');
        }

        $evidence = [
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'fixture_items' => $expectedItems,
            'duration_ms' => max(0, intdiv(hrtime(true) - $start, 1000000)),
            'payload_fingerprint' => $digest,
            'created_at' => now(),
        ];
        DB::table('offline_recovery_exercises')->insert($evidence);

        return ['id' => $evidence['id'], 'duration_ms' => $evidence['duration_ms'], 'fixture_items' => $expectedItems];
    }
}
