<?php

namespace App\Modules\Providers\Infrastructure\ConnectorFactory;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorLifecycleHealth;
use App\Modules\Providers\Domain\ConnectorFactory\Contracts\ConnectorLifecycleHealthRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class DatabaseConnectorLifecycleHealthRepository implements ConnectorLifecycleHealthRepository
{
    public function record(ConnectorLifecycleHealth $health): ConnectorLifecycleHealth
    {
        $this->assertScope($health->workspaceId, $health->providerKey);

        return DB::transaction(function () use ($health): ConnectorLifecycleHealth {
            $now = $health->observedAt;
            DB::table('connector_lifecycle_health')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'workspace_id' => $health->workspaceId,
                'provider_key' => $health->providerKey,
                'status' => $health->status,
                'reason' => $health->reason,
                'observed_at' => $health->observedAt,
                'compatibility_evidence_sha256' => $health->compatibilityEvidenceSha256,
                'deprecation_evidence_sha256' => $health->deprecationEvidenceSha256,
                'decision_audit_sha256' => $health->decisionAuditSha256,
                'failure_code' => $health->failureCode,
                'reconciliation_key' => $health->reconciliationKey,
                'evidence_sha256' => $health->evidenceSha256,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = DB::table('connector_lifecycle_health')
                ->where('workspace_id', $health->workspaceId)
                ->where('provider_key', $health->providerKey)
                ->where('reconciliation_key', $health->reconciliationKey)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException('Connector lifecycle evidence was not persisted.');
            }

            $persisted = $this->fromRow($row);
            if ($persisted->evidenceSha256 !== $health->evidenceSha256) {
                throw new InvalidArgumentException('A lifecycle reconciliation key cannot be reused for different evidence.');
            }

            return $persisted;
        }, 3);
    }

    public function findByReconciliationKey(string $workspaceId, string $providerKey, string $reconciliationKey): ?ConnectorLifecycleHealth
    {
        $this->assertScope($workspaceId, $providerKey);
        $this->assertDigest($reconciliationKey, 'Reconciliation key');

        $row = DB::table('connector_lifecycle_health')
            ->where('workspace_id', $workspaceId)
            ->where('provider_key', $providerKey)
            ->where('reconciliation_key', $reconciliationKey)
            ->first();

        return $row === null ? null : $this->fromRow($row);
    }

    public function findLatestForProvider(string $workspaceId, string $providerKey): ?ConnectorLifecycleHealth
    {
        $this->assertScope($workspaceId, $providerKey);

        $row = DB::table('connector_lifecycle_health')
            ->where('workspace_id', $workspaceId)
            ->where('provider_key', $providerKey)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : $this->fromRow($row);
    }

    private function fromRow(object $row): ConnectorLifecycleHealth
    {
        $health = new ConnectorLifecycleHealth(
            workspaceId: (string) $row->workspace_id,
            providerKey: (string) $row->provider_key,
            status: (string) $row->status,
            reason: (string) $row->reason,
            observedAt: new DateTimeImmutable((string) $row->observed_at),
            compatibilityEvidenceSha256: (string) $row->compatibility_evidence_sha256,
            deprecationEvidenceSha256: $row->deprecation_evidence_sha256 === null ? null : (string) $row->deprecation_evidence_sha256,
            decisionAuditSha256: $row->decision_audit_sha256 === null ? null : (string) $row->decision_audit_sha256,
            failureCode: $row->failure_code === null ? null : (string) $row->failure_code,
            reconciliationKey: (string) $row->reconciliation_key,
        );

        if ($health->evidenceSha256 !== (string) $row->evidence_sha256) {
            throw new RuntimeException('Persisted connector lifecycle evidence failed its integrity check.');
        }

        return $health;
    }

    private function assertScope(string $workspaceId, string $providerKey): void
    {
        if (! Str::isUuid($workspaceId) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException('Lifecycle evidence lookup requires a valid workspace and provider scope.');
        }
    }

    private function assertDigest(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label.' must be a SHA-256 digest.');
        }
    }
}
