<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ConnectorLifecycleDecision
{
    public string $auditSha256;

    public function __construct(
        public string $workspaceId,
        public string $providerKey,
        public string $action,
        public string $reason,
        public string $actorId,
        public string $idempotencyKey,
        public DateTimeImmutable $decidedAt,
        public string $compatibilityEvidenceSha256,
        public ?string $rollbackCandidateId = null,
    ) {
        if (trim($workspaceId) === '' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $providerKey) !== 1
            || ! in_array($action, ['disable', 'rollback'], true)
            || trim($reason) === '' || trim($actorId) === ''
            || preg_match('/^[a-f0-9]{64}$/D', $idempotencyKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $compatibilityEvidenceSha256) !== 1
            || ($action === 'rollback' && ($rollbackCandidateId === null || preg_match('/^[a-f0-9]{64}$/D', $rollbackCandidateId) !== 1))
            || ($action === 'disable' && $rollbackCandidateId !== null)
            || $decidedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Lifecycle decision must be scoped, auditable, UTC-dated and explicit.');
        }

        $this->auditSha256 = hash('sha256', json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->payload(), 'audit_sha256' => $this->auditSha256];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'workspace_id' => $this->workspaceId,
            'provider_key' => $this->providerKey,
            'action' => $this->action,
            'reason' => $this->reason,
            'actor_id' => $this->actorId,
            'idempotency_key' => $this->idempotencyKey,
            'decided_at' => $this->decidedAt->format(DATE_ATOM),
            'compatibility_evidence_sha256' => $this->compatibilityEvidenceSha256,
            'rollback_candidate_id' => $this->rollbackCandidateId,
        ];
    }
}
