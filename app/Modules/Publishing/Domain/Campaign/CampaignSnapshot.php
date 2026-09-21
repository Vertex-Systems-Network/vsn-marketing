<?php

namespace App\Modules\Publishing\Domain\Campaign;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CampaignSnapshot
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param list<string> $componentVersionIds
     * @param list<string> $assetReferenceIds
     * @param list<string> $capabilityEvidenceIds
     * @param array<string, mixed> $brandReference
     * @param array<string, mixed> $intendedExecution
     * @param list<CampaignTargetBinding> $targets
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $campaignId,
        public ?string $parentSnapshotId,
        public int $versionNumber,
        public int $schemaVersion,
        public string $contentVersionId,
        public ?string $templateVersionId,
        public array $componentVersionIds,
        public array $assetReferenceIds,
        public array $capabilityEvidenceIds,
        public array $brandReference,
        public array $intendedExecution,
        public array $targets,
        public string $targetSetHash,
        public string $snapshotHash,
        public string $idempotencyKey,
        public string $createdByActorId,
        public DateTimeImmutable $createdAt,
    ) {
        CampaignPayloadGuard::assertIdentifier($this->id, 'snapshot.id');
        CampaignPayloadGuard::assertIdentifier($this->workspaceId, 'snapshot.workspaceId');
        CampaignPayloadGuard::assertIdentifier($this->campaignId, 'snapshot.campaignId');
        CampaignPayloadGuard::assertIdentifier($this->contentVersionId, 'snapshot.contentVersionId');
        CampaignPayloadGuard::assertIdentifier($this->idempotencyKey, 'snapshot.idempotencyKey');
        CampaignPayloadGuard::assertIdentifier($this->createdByActorId, 'snapshot.createdByActorId');

        if ($this->templateVersionId !== null) {
            CampaignPayloadGuard::assertIdentifier($this->templateVersionId, 'snapshot.templateVersionId');
        }

        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported campaign snapshot schema version: {$this->schemaVersion}");
        }

        if ($this->versionNumber < 1 || (($this->versionNumber === 1) !== ($this->parentSnapshotId === null))) {
            throw new InvalidArgumentException('Campaign snapshot lineage is invalid.');
        }

        if ($this->parentSnapshotId !== null) {
            CampaignPayloadGuard::assertIdentifier($this->parentSnapshotId, 'snapshot.parentSnapshotId');
        }

        CampaignPayloadGuard::assertIdentifierList($this->componentVersionIds, 'snapshot.componentVersionIds');
        CampaignPayloadGuard::assertIdentifierList($this->assetReferenceIds, 'snapshot.assetReferenceIds');
        CampaignPayloadGuard::assertIdentifierList($this->capabilityEvidenceIds, 'snapshot.capabilityEvidenceIds');
        CampaignPayloadGuard::assertPublicJson($this->brandReference, 'snapshot.brandReference');
        CampaignPayloadGuard::assertPublicJson($this->intendedExecution, 'snapshot.intendedExecution');

        if ($this->targets === []) {
            throw new InvalidArgumentException('Campaign snapshot must bind at least one canonical target.');
        }

        $targetIds = [];
        foreach ($this->targets as $target) {
            if (! $target instanceof CampaignTargetBinding) {
                throw new InvalidArgumentException('Campaign snapshot targets must contain CampaignTargetBinding values.');
            }

            if ($target->workspaceId !== $this->workspaceId) {
                throw new InvalidArgumentException('Campaign snapshot targets cannot cross workspaces.');
            }

            if (isset($targetIds[$target->id])) {
                throw new InvalidArgumentException("Duplicate campaign target id: {$target->id}");
            }

            $targetIds[$target->id] = true;
        }

        CampaignPayloadGuard::assertSha256($this->targetSetHash, 'snapshot.targetSetHash');
        CampaignPayloadGuard::assertSha256($this->snapshotHash, 'snapshot.snapshotHash');

        $expectedTargetSetHash = self::calculateTargetSetHash($this->targets);
        if (! hash_equals($expectedTargetSetHash, $this->targetSetHash)) {
            throw new InvalidArgumentException('Campaign snapshot target-set hash does not match canonical targets.');
        }

        $expectedSnapshotHash = CampaignPayloadGuard::hash($this->canonicalPayload());
        if (! hash_equals($expectedSnapshotHash, $this->snapshotHash)) {
            throw new InvalidArgumentException('Campaign snapshot hash does not match canonical snapshot payload.');
        }
    }

    /**
     * @param list<string> $componentVersionIds
     * @param list<string> $assetReferenceIds
     * @param list<string> $capabilityEvidenceIds
     * @param array<string, mixed> $brandReference
     * @param array<string, mixed> $intendedExecution
     * @param list<CampaignTargetBinding> $targets
     */
    public static function create(
        string $id,
        string $workspaceId,
        string $campaignId,
        ?string $parentSnapshotId,
        int $versionNumber,
        string $contentVersionId,
        ?string $templateVersionId,
        array $componentVersionIds,
        array $assetReferenceIds,
        array $capabilityEvidenceIds,
        array $brandReference,
        array $intendedExecution,
        array $targets,
        string $idempotencyKey,
        string $createdByActorId,
        DateTimeImmutable $createdAt,
    ): self {
        $targetSetHash = self::calculateTargetSetHash($targets);
        $payload = self::canonicalPayloadFor(
            workspaceId: $workspaceId,
            campaignId: $campaignId,
            parentSnapshotId: $parentSnapshotId,
            versionNumber: $versionNumber,
            contentVersionId: $contentVersionId,
            templateVersionId: $templateVersionId,
            componentVersionIds: $componentVersionIds,
            assetReferenceIds: $assetReferenceIds,
            capabilityEvidenceIds: $capabilityEvidenceIds,
            brandReference: $brandReference,
            intendedExecution: $intendedExecution,
            targetSetHash: $targetSetHash,
        );

        return new self(
            id: $id,
            workspaceId: $workspaceId,
            campaignId: $campaignId,
            parentSnapshotId: $parentSnapshotId,
            versionNumber: $versionNumber,
            schemaVersion: self::SCHEMA_VERSION,
            contentVersionId: $contentVersionId,
            templateVersionId: $templateVersionId,
            componentVersionIds: $componentVersionIds,
            assetReferenceIds: $assetReferenceIds,
            capabilityEvidenceIds: $capabilityEvidenceIds,
            brandReference: $brandReference,
            intendedExecution: $intendedExecution,
            targets: $targets,
            targetSetHash: $targetSetHash,
            snapshotHash: CampaignPayloadGuard::hash($payload),
            idempotencyKey: $idempotencyKey,
            createdByActorId: $createdByActorId,
            createdAt: $createdAt,
        );
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return self::canonicalPayloadFor(
            workspaceId: $this->workspaceId,
            campaignId: $this->campaignId,
            parentSnapshotId: $this->parentSnapshotId,
            versionNumber: $this->versionNumber,
            contentVersionId: $this->contentVersionId,
            templateVersionId: $this->templateVersionId,
            componentVersionIds: $this->componentVersionIds,
            assetReferenceIds: $this->assetReferenceIds,
            capabilityEvidenceIds: $this->capabilityEvidenceIds,
            brandReference: $this->brandReference,
            intendedExecution: $this->intendedExecution,
            targetSetHash: $this->targetSetHash,
        );
    }

    /** @param list<CampaignTargetBinding> $targets */
    private static function calculateTargetSetHash(array $targets): string
    {
        $payloads = array_map(
            static fn (CampaignTargetBinding $target): array => $target->canonicalPayload(),
            $targets,
        );

        usort(
            $payloads,
            static fn (array $left, array $right): int => CampaignPayloadGuard::hash($left) <=> CampaignPayloadGuard::hash($right),
        );

        return CampaignPayloadGuard::hash($payloads);
    }

    /**
     * @param list<string> $componentVersionIds
     * @param list<string> $assetReferenceIds
     * @param list<string> $capabilityEvidenceIds
     * @param array<string, mixed> $brandReference
     * @param array<string, mixed> $intendedExecution
     * @return array<string, mixed>
     */
    private static function canonicalPayloadFor(
        string $workspaceId,
        string $campaignId,
        ?string $parentSnapshotId,
        int $versionNumber,
        string $contentVersionId,
        ?string $templateVersionId,
        array $componentVersionIds,
        array $assetReferenceIds,
        array $capabilityEvidenceIds,
        array $brandReference,
        array $intendedExecution,
        string $targetSetHash,
    ): array {
        $components = $componentVersionIds;
        $assets = $assetReferenceIds;
        $capabilities = $capabilityEvidenceIds;
        sort($components, SORT_STRING);
        sort($assets, SORT_STRING);
        sort($capabilities, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'workspace_id' => $workspaceId,
            'campaign_id' => $campaignId,
            'parent_snapshot_id' => $parentSnapshotId,
            'version_number' => $versionNumber,
            'content_version_id' => $contentVersionId,
            'template_version_id' => $templateVersionId,
            'component_version_ids' => $components,
            'asset_reference_ids' => $assets,
            'capability_evidence_ids' => $capabilities,
            'brand_reference' => $brandReference,
            'intended_execution' => $intendedExecution,
            'target_set_hash' => $targetSetHash,
        ];
    }
}
