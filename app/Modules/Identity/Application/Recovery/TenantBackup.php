<?php

namespace App\Modules\Identity\Application\Recovery;

use App\Modules\Audit\Application\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encrypted, tenant-separated logical backup and idempotent restore (ADR-0006:
 * RPO 24h / RTO 4h). Restore inserts missing rows only, so it never replays
 * external effects or mutates append-only evidence, and it is all-or-nothing.
 */
final readonly class TenantBackup
{
    /** Restore order respects foreign keys. Each table carries workspace_id. */
    public const TABLES = ['contacts', 'contact_identities', 'contact_lists', 'tags', 'contact_list_memberships',
        'contact_tag_assignments', 'consent_records', 'event_types', 'customer_events',
        'workspace_data_policies', 'data_legal_holds'];

    public function __construct(private AuditRecorder $audit) {}

    public function create(string $workspaceId, string $key): string
    {
        $tables = [];
        $hashes = [];
        foreach (self::TABLES as $table) {
            $rows = DB::table($table)->where('workspace_id', $workspaceId)->get()
                ->map(fn ($row): array => (array) $row)->sortBy(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))
                ->values()->all();
            $tables[$table] = $rows;
            $hashes[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }
        $auditHead = DB::table('audit_events')->where('workspace_id', $workspaceId)->orderByDesc('occurred_at')->orderByDesc('id')->value('id');
        $manifest = ['backup_id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'taken_at' => time(),
            'hashes' => $hashes, 'audit_head' => $auditHead];
        $plaintext = json_encode(['manifest' => $manifest, 'tables' => $tables], JSON_THROW_ON_ERROR);
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->rawKey($key), OPENSSL_RAW_DATA, $iv, $tag, $workspaceId);
        if ($ciphertext === false) {
            throw new RuntimeException('Backup encryption failed.');
        }
        $this->audit->record($workspaceId, 'recovery.backup.created', ['backup_id' => $manifest['backup_id'], 'hashes' => $hashes]);

        return base64_encode($iv.$tag.$ciphertext);
    }

    /** @return array{backup_id: string, taken_at: int, restored: array<string, int>} */
    public function restore(string $blob, string $key, string $targetWorkspaceId): array
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Backup is unreadable.');
        }
        // The workspace id is authenticated data: a backup cannot be restored into another tenant.
        $plaintext = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->rawKey($key), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16), $targetWorkspaceId);
        if ($plaintext === false) {
            throw new RuntimeException('Backup cannot be decrypted with this key for this workspace.');
        }
        $backup = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        $manifest = $backup['manifest'];
        if ($manifest['workspace_id'] !== $targetWorkspaceId) {
            throw new RuntimeException('Backup tenant mismatch.');
        }
        foreach (self::TABLES as $table) {
            $rows = $backup['tables'][$table] ?? null;
            if (! is_array($rows) || ! hash_equals($manifest['hashes'][$table] ?? '', hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('Backup integrity check failed for '.$table.'.');
            }
            foreach ($rows as $row) {
                if (($row['workspace_id'] ?? null) !== $targetWorkspaceId) {
                    throw new RuntimeException('Backup row outside tenant boundary.');
                }
            }
        }

        $restored = DB::transaction(function () use ($backup): array {
            $counts = [];
            foreach (self::TABLES as $table) {
                $counts[$table] = 0;
                foreach (array_chunk($backup['tables'][$table], 200) as $chunk) {
                    $counts[$table] += DB::table($table)->insertOrIgnore($chunk);
                }
            }

            return $counts;
        });
        $this->audit->record($targetWorkspaceId, 'recovery.backup.restored', ['backup_id' => $manifest['backup_id'],
            'source_audit_head' => $manifest['audit_head'], 'restored' => $restored]);

        return ['backup_id' => $manifest['backup_id'], 'taken_at' => (int) $manifest['taken_at'], 'restored' => $restored];
    }

    /**
     * Audit actions recorded after the backup point that describe external effects.
     * They are surfaced for reconciliation and never replayed by restore.
     *
     * @return list<array{id: string, action: string}>
     */
    public function externalEffectsSince(string $workspaceId, int $takenAt): array
    {
        return DB::table('audit_events')->where('workspace_id', $workspaceId)
            ->where('occurred_at', '>', date('Y-m-d H:i:s', $takenAt))
            ->where(fn ($q) => $q->where('action', 'like', 'delivery.%')->orWhere('action', 'like', 'billing.%')
                ->orWhere('action', 'like', 'publishing.%'))
            ->orderBy('occurred_at')->get(['id', 'action'])
            ->map(fn ($row): array => ['id' => (string) $row->id, 'action' => (string) $row->action])->all();
    }

    /**
     * Measured exercise: back up, lose data, restore, and record actual loss and
     * elapsed time against RPO 24h / RTO 4h.
     *
     * @param  callable(): void  $disaster
     * @return array<string, mixed>
     */
    public function exercise(string $workspaceId, string $key, callable $disaster, int $lastBackupAgeSeconds = 0): array
    {
        $before = $this->counts($workspaceId);
        $blob = $this->create($workspaceId, $key);
        $disaster();
        $started = hrtime(true);
        $this->restore($blob, $key, $workspaceId);
        $elapsedSeconds = (hrtime(true) - $started) / 1e9;
        $after = $this->counts($workspaceId);
        $lostRows = array_sum(array_map(fn (string $t): int => max(0, $before[$t] - $after[$t]), self::TABLES));
        $result = [
            'workspace_id' => $workspaceId, 'rows_before' => array_sum($before), 'rows_lost_after_restore' => $lostRows,
            'data_loss_window_seconds' => $lastBackupAgeSeconds, 'restore_elapsed_seconds' => round($elapsedSeconds, 3),
            'rpo_seconds' => 86400, 'rto_seconds' => 14400,
            'rpo_met' => $lostRows === 0 && $lastBackupAgeSeconds <= 86400, 'rto_met' => $elapsedSeconds <= 14400,
        ];
        DB::table('recovery_exercises')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
            'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $this->audit->record($workspaceId, 'recovery.exercise.recorded', $result);

        return $result;
    }

    /** @return array<string, int> */
    private function counts(string $workspaceId): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->where('workspace_id', $workspaceId)->count();
        }

        return $counts;
    }

    private function rawKey(string $key): string
    {
        $raw = base64_decode($key, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('Backup key must be 32 bytes, base64 encoded.');
        }

        return $raw;
    }
}
