<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ConnectorLifecycleHealth
{
    public string $evidenceSha256;

    public function __construct(
        public string $workspaceId,
        public string $providerKey,
        public string $status,
        public string $reason,
        public DateTimeImmutable $observedAt,
        public string $compatibilityEvidenceSha256,
        public ?string $deprecationEvidenceSha256,
        public ?string $decisionAuditSha256,
        public ?string $failureCode,
        public string $reconciliationKey,
    ) {
        if (trim($workspaceId) === '' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $providerKey) !== 1
            || ! in_array($status, ['healthy', 'degraded', 'blocked', 'disabled', 'rollback_pending'], true)
            || trim($reason) === '' || $observedAt->getOffset() !== 0
            || preg_match('/^[a-f0-9]{64}$/D', $compatibilityEvidenceSha256) !== 1
            || ($deprecationEvidenceSha256 !== null && preg_match('/^[a-f0-9]{64}$/D', $deprecationEvidenceSha256) !== 1)
            || ($decisionAuditSha256 !== null && preg_match('/^[a-f0-9]{64}$/D', $decisionAuditSha256) !== 1)
            || ($failureCode !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $failureCode) !== 1)
            || preg_match('/^[a-f0-9]{64}$/D', $reconciliationKey) !== 1) {
            throw new InvalidArgumentException('Lifecycle health must be tenant-scoped, UTC-dated and evidence-backed.');
        }

        $this->evidenceSha256 = hash('sha256', json_encode(
            $this->payload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->payload(), 'evidence_sha256' => $this->evidenceSha256];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'workspace_id' => $this->workspaceId,
            'provider_key' => $this->providerKey,
            'status' => $this->status,
            'reason' => $this->reason,
            'observed_at' => $this->observedAt->format(DATE_ATOM),
            'compatibility_evidence_sha256' => $this->compatibilityEvidenceSha256,
            'deprecation_evidence_sha256' => $this->deprecationEvidenceSha256,
            'decision_audit_sha256' => $this->decisionAuditSha256,
            'failure_code' => $this->failureCode,
            'reconciliation_key' => $this->reconciliationKey,
        ];
    }
}
