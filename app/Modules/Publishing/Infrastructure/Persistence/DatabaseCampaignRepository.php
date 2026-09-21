<?php

namespace App\Modules\Publishing\Infrastructure\Persistence;

use App\Modules\Publishing\Domain\Campaign\Campaign;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalDecision;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignEvent;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetBinding;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetKind;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class DatabaseCampaignRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function createCampaign(Campaign $campaign, CampaignEvent $event): Campaign
    {
        return $this->database->connection()->transaction(function () use ($campaign, $event): Campaign {
            $this->assertCreationEvent($campaign, $event);

            $existing = $this->campaignByIdempotency($campaign->workspaceId, $campaign->idempotencyKey, true);
            if ($existing instanceof stdClass) {
                $stored = $this->hydrateCampaign($existing);
                $this->assertCampaignReplay($stored, $campaign);
                $this->appendEventInternal($event);

                return $stored;
            }

            $this->denyIfForeignCampaignIdExists($campaign->workspaceId, $campaign->id);

            $inserted = $this->database->connection()->table('campaigns')->insertOrIgnore([
                'id' => $campaign->id,
                'workspace_id' => $campaign->workspaceId,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'state_version' => $campaign->stateVersion,
                'idempotency_key' => $campaign->idempotencyKey,
                'created_by_actor_id' => $campaign->createdByActorId,
                'created_at' => $campaign->createdAt,
                'updated_at' => $campaign->updatedAt,
            ]);

            if ($inserted !== 1) {
                $winner = $this->campaignByIdempotency($campaign->workspaceId, $campaign->idempotencyKey, true)
                    ?? $this->database->connection()->table('campaigns')
                        ->where('workspace_id', $campaign->workspaceId)
                        ->where('id', $campaign->id)
                        ->lockForUpdate()
                        ->first();

                if (! $winner instanceof stdClass) {
                    $this->denyIfForeignCampaignIdExists($campaign->workspaceId, $campaign->id);
                    throw new InvalidArgumentException('Campaign identity or idempotency key conflicts with existing state.');
                }

                $stored = $this->hydrateCampaign($winner);
                $this->assertCampaignReplay($stored, $campaign);
                $this->appendEventInternal($event);

                return $stored;
            }

            $this->appendEventInternal($event);

            return $campaign;
        });
    }

    public function findCampaign(string $workspaceId, string $campaignId): ?Campaign
    {
        $row = $this->database->connection()->table('campaigns')
            ->where('workspace_id', $workspaceId)
            ->where('id', $campaignId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrateCampaign($row);
        }

        $this->denyIfForeignCampaignIdExists($workspaceId, $campaignId);

        return null;
    }

    public function transitionCampaign(
        Campaign $next,
        int $expectedStateVersion,
        CampaignEvent $event,
    ): Campaign {
        return $this->database->connection()->transaction(function () use ($next, $expectedStateVersion, $event): Campaign {
            $row = $this->database->connection()->table('campaigns')
                ->where('workspace_id', $next->workspaceId)
                ->where('id', $next->id)
                ->lockForUpdate()
                ->first();

            if (! $row instanceof stdClass) {
                $this->denyIfForeignCampaignIdExists($next->workspaceId, $next->id);
                throw new InvalidArgumentException('Campaign does not exist in this workspace.');
            }

            $stored = $this->hydrateCampaign($row);
            $existingEvent = $this->eventByIdempotency($event->workspaceId, $event->idempotencyKey, true);

            if ($existingEvent instanceof stdClass) {
                $this->assertEventReplay($this->hydrateEvent($existingEvent), $event);
                $this->assertCampaignReplay($stored, $next);

                return $stored;
            }

            if ($stored->stateVersion !== $expectedStateVersion) {
                throw new InvalidArgumentException('Campaign optimistic concurrency conflict.');
            }

            $this->assertTransition($stored, $next, $event);

            $updated = $this->database->connection()->table('campaigns')
                ->where('workspace_id', $next->workspaceId)
                ->where('id', $next->id)
                ->where('state_version', $expectedStateVersion)
                ->update([
                    'status' => $next->status->value,
                    'state_version' => $next->stateVersion,
                    'updated_at' => $next->updatedAt,
                ]);

            if ($updated !== 1) {
                throw new InvalidArgumentException('Campaign optimistic concurrency conflict.');
            }

            $this->appendEventInternal($event);

            return $next;
        });
    }

    public function appendSnapshot(CampaignSnapshot $snapshot, CampaignEvent $event): CampaignSnapshot
    {
        return $this->database->connection()->transaction(function () use ($snapshot, $event): CampaignSnapshot {
            $this->assertSnapshotEvent($snapshot, $event);
            $this->lockCampaign($snapshot->workspaceId, $snapshot->campaignId);

            $existing = $this->snapshotByIdempotency($snapshot->workspaceId, $snapshot->idempotencyKey, true);
            if ($existing instanceof stdClass) {
                $stored = $this->hydrateSnapshot($existing);
                $this->assertSnapshotReplay($stored, $snapshot);
                $this->appendEventInternal($event);

                return $stored;
            }

            $this->denyIfForeignSnapshotIdExists($snapshot->workspaceId, $snapshot->id);
            $this->assertSnapshotLineage($snapshot);
            $this->assertSnapshotReferences($snapshot);

            foreach ($snapshot->targets as $target) {
                $this->assertTargetReference($snapshot->workspaceId, $target);
            }

            $inserted = $this->database->connection()->table('campaign_snapshots')->insertOrIgnore([
                'id' => $snapshot->id,
                'workspace_id' => $snapshot->workspaceId,
                'campaign_id' => $snapshot->campaignId,
                'parent_snapshot_id' => $snapshot->parentSnapshotId,
                'version_number' => $snapshot->versionNumber,
                'schema_version' => $snapshot->schemaVersion,
                'content_version_id' => $snapshot->contentVersionId,
                'template_version_id' => $snapshot->templateVersionId,
                'component_version_ids' => $this->encode($snapshot->componentVersionIds),
                'asset_reference_ids' => $this->encode($snapshot->assetReferenceIds),
                'capability_evidence_ids' => $this->encode($snapshot->capabilityEvidenceIds),
                'brand_reference' => $this->encode($snapshot->brandReference),
                'intended_execution' => $this->encode($snapshot->intendedExecution),
                'target_set_hash' => $snapshot->targetSetHash,
                'snapshot_hash' => $snapshot->snapshotHash,
                'idempotency_key' => $snapshot->idempotencyKey,
                'created_by_actor_id' => $snapshot->createdByActorId,
                'created_at' => $snapshot->createdAt,
            ]);

            if ($inserted !== 1) {
                $winner = $this->snapshotByIdempotency($snapshot->workspaceId, $snapshot->idempotencyKey, true)
                    ?? $this->database->connection()->table('campaign_snapshots')
                        ->where('workspace_id', $snapshot->workspaceId)
                        ->where('id', $snapshot->id)
                        ->lockForUpdate()
                        ->first();

                if (! $winner instanceof stdClass) {
                    $this->denyIfForeignSnapshotIdExists($snapshot->workspaceId, $snapshot->id);
                    throw new InvalidArgumentException('Campaign snapshot identity or idempotency key conflicts with existing state.');
                }
            }

            foreach ($snapshot->targets as $target) {
                $this->persistTarget($snapshot, $target);
            }

            $storedRow = $this->database->connection()->table('campaign_snapshots')
                ->where('workspace_id', $snapshot->workspaceId)
                ->where('id', $snapshot->id)
                ->first();

            if (! $storedRow instanceof stdClass) {
                throw new InvalidArgumentException('Campaign snapshot persistence did not produce readable state.');
            }

            $stored = $this->hydrateSnapshot($storedRow);
            $this->assertSnapshotReplay($stored, $snapshot);
            $this->appendEventInternal($event);

            return $stored;
        });
    }

    public function findSnapshot(string $workspaceId, string $snapshotId): ?CampaignSnapshot
    {
        $row = $this->database->connection()->table('campaign_snapshots')
            ->where('workspace_id', $workspaceId)
            ->where('id', $snapshotId)
            ->first();

        if ($row instanceof stdClass) {
            return $this->hydrateSnapshot($row);
        }

        $this->denyIfForeignSnapshotIdExists($workspaceId, $snapshotId);

        return null;
    }

    public function latestSnapshot(string $workspaceId, string $campaignId): ?CampaignSnapshot
    {
        $this->assertCampaignScope($workspaceId, $campaignId);

        $row = $this->database->connection()->table('campaign_snapshots')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->orderByDesc('version_number')
            ->first();

        return $row instanceof stdClass ? $this->hydrateSnapshot($row) : null;
    }

    /** @return list<CampaignApprovalDecision> */
    public function approvalDecisions(
        string $workspaceId,
        string $campaignId,
        string $snapshotId,
    ): array {
        $this->assertCampaignScope($workspaceId, $campaignId);

        $snapshot = $this->database->connection()->table('campaign_snapshots')
            ->where('workspace_id', $workspaceId)
            ->where('id', $snapshotId)
            ->first();

        if (! $snapshot instanceof stdClass) {
            $this->denyIfForeignSnapshotIdExists($workspaceId, $snapshotId);
            throw new InvalidArgumentException('Campaign approval snapshot does not exist in this workspace.');
        }

        if ((string) $snapshot->campaign_id !== $campaignId) {
            throw new InvalidArgumentException('Campaign approval snapshot belongs to a different campaign.');
        }

        return $this->database->connection()->table('campaign_approval_decisions')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->where('snapshot_id', $snapshotId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): CampaignApprovalDecision => $this->hydrateApproval($row))
            ->values()
            ->all();
    }

    public function appendApproval(
        CampaignApprovalDecision $decision,
        CampaignEvent $event,
    ): CampaignApprovalDecision {
        return $this->database->connection()->transaction(function () use ($decision, $event): CampaignApprovalDecision {
            $this->assertApprovalEvent($decision, $event);
            $campaignRow = $this->lockCampaign($decision->workspaceId, $decision->campaignId);
            $campaignStatus = CampaignStatus::from((string) $campaignRow->status);

            if (
                in_array($decision->outcome, [CampaignApprovalOutcome::Approved, CampaignApprovalOutcome::Rejected], true)
                && $campaignStatus !== CampaignStatus::NeedsApproval
            ) {
                throw new InvalidArgumentException('Campaign approval or rejection requires needs_approval lifecycle state.');
            }

            if (
                $decision->outcome === CampaignApprovalOutcome::Revoked
                && ! in_array($campaignStatus, [CampaignStatus::Approved, CampaignStatus::Ready, CampaignStatus::ScheduledIntent], true)
            ) {
                throw new InvalidArgumentException('Campaign approval revocation requires an approved or execution-intent lifecycle state.');
            }

            $existing = $this->approvalByIdempotency($decision->workspaceId, $decision->idempotencyKey, true);
            if ($existing instanceof stdClass) {
                $stored = $this->hydrateApproval($existing);
                $this->assertApprovalReplay($stored, $decision);
                $this->appendEventInternal($event);

                return $stored;
            }

            $snapshot = $this->database->connection()->table('campaign_snapshots')
                ->where('workspace_id', $decision->workspaceId)
                ->where('id', $decision->snapshotId)
                ->lockForUpdate()
                ->first();

            if (! $snapshot instanceof stdClass) {
                $this->denyIfForeignSnapshotIdExists($decision->workspaceId, $decision->snapshotId);
                throw new InvalidArgumentException('Campaign approval snapshot does not exist in this workspace.');
            }

            if ((string) $snapshot->campaign_id !== $decision->campaignId) {
                throw new InvalidArgumentException('Campaign approval snapshot belongs to a different campaign.');
            }

            if (! hash_equals((string) $snapshot->target_set_hash, $decision->targetSetHash)) {
                throw new InvalidArgumentException('Campaign approval target set does not match the immutable snapshot.');
            }

            $snapshotCapabilities = $this->decodeList((string) $snapshot->capability_evidence_ids);
            $decisionCapabilities = $decision->capabilityEvidenceIds;
            sort($snapshotCapabilities, SORT_STRING);
            sort($decisionCapabilities, SORT_STRING);

            if ($snapshotCapabilities !== $decisionCapabilities) {
                throw new InvalidArgumentException('Campaign approval capability evidence must match the immutable snapshot.');
            }

            if ($this->database->connection()->table('campaign_targets')
                ->where('workspace_id', $decision->workspaceId)
                ->where('snapshot_id', $decision->snapshotId)
                ->count() < 1) {
                throw new InvalidArgumentException('Campaign approval requires at least one immutable target binding.');
            }

            foreach ($decision->capabilityEvidenceIds as $capabilityId) {
                $this->assertCapability($decision->workspaceId, $capabilityId);
            }

            if ($decision->supersedesDecisionId !== null) {
                $superseded = $this->database->connection()->table('campaign_approval_decisions')
                    ->where('workspace_id', $decision->workspaceId)
                    ->where('id', $decision->supersedesDecisionId)
                    ->first();

                if (! $superseded instanceof stdClass) {
                    $this->denyIfForeignApprovalIdExists($decision->workspaceId, $decision->supersedesDecisionId);
                    throw new InvalidArgumentException('Superseded campaign approval decision does not exist in this workspace.');
                }

                if (
                    (string) $superseded->campaign_id !== $decision->campaignId
                    || (string) $superseded->snapshot_id !== $decision->snapshotId
                    || CampaignApprovalOutcome::from((string) $superseded->outcome) !== CampaignApprovalOutcome::Approved
                ) {
                    throw new InvalidArgumentException('Campaign approval revocation must supersede an approved decision for the same snapshot.');
                }
            }

            $this->denyIfForeignApprovalIdExists($decision->workspaceId, $decision->id);

            $inserted = $this->database->connection()->table('campaign_approval_decisions')->insertOrIgnore([
                'id' => $decision->id,
                'workspace_id' => $decision->workspaceId,
                'campaign_id' => $decision->campaignId,
                'snapshot_id' => $decision->snapshotId,
                'target_set_hash' => $decision->targetSetHash,
                'outcome' => $decision->outcome->value,
                'actor_id' => $decision->actorId,
                'actor_role' => $decision->actorRole,
                'reason' => $decision->reason,
                'capability_evidence_ids' => $this->encode($decision->capabilityEvidenceIds),
                'supersedes_decision_id' => $decision->supersedesDecisionId,
                'expires_at' => $decision->expiresAt,
                'idempotency_key' => $decision->idempotencyKey,
                'occurred_at' => $decision->occurredAt,
            ]);

            if ($inserted !== 1) {
                $winner = $this->approvalByIdempotency($decision->workspaceId, $decision->idempotencyKey, true)
                    ?? $this->database->connection()->table('campaign_approval_decisions')
                        ->where('workspace_id', $decision->workspaceId)
                        ->where('id', $decision->id)
                        ->first();

                if (! $winner instanceof stdClass) {
                    $this->denyIfForeignApprovalIdExists($decision->workspaceId, $decision->id);
                    throw new InvalidArgumentException('Campaign approval identity or idempotency key conflicts with existing state.');
                }

                $stored = $this->hydrateApproval($winner);
                $this->assertApprovalReplay($stored, $decision);
                $this->appendEventInternal($event);

                return $stored;
            }

            $this->appendEventInternal($event);

            return $decision;
        });
    }

    /** @return list<CampaignEvent> */
    public function history(string $workspaceId, string $campaignId): array
    {
        $this->assertCampaignScope($workspaceId, $campaignId);

        return $this->database->connection()->table('campaign_events')
            ->where('workspace_id', $workspaceId)
            ->where('campaign_id', $campaignId)
            ->orderBy('sequence')
            ->get()
            ->map(fn (stdClass $row): CampaignEvent => $this->hydrateEvent($row))
            ->values()
            ->all();
    }

    private function persistTarget(CampaignSnapshot $snapshot, CampaignTargetBinding $target): void
    {
        $inserted = $this->database->connection()->table('campaign_targets')->insertOrIgnore([
            'id' => $target->id,
            'workspace_id' => $snapshot->workspaceId,
            'snapshot_id' => $snapshot->id,
            'kind' => $target->kind->value,
            'canonical_reference_id' => $target->canonicalReferenceId,
            'channel' => $target->channel,
            'provider_connection_id' => $target->providerConnectionId,
            'capability_evidence_id' => $target->capabilityEvidenceId,
            'metadata' => $this->encode($target->metadata),
            'target_hash' => $target->fingerprint(),
            'created_at' => $target->createdAt,
        ]);

        if ($inserted === 1) {
            return;
        }

        $row = $this->database->connection()->table('campaign_targets')
            ->where('workspace_id', $snapshot->workspaceId)
            ->where(function ($query) use ($snapshot, $target): void {
                $query->where('id', $target->id)
                    ->orWhere(function ($identity) use ($snapshot, $target): void {
                        $identity->where('snapshot_id', $snapshot->id)
                            ->where('kind', $target->kind->value)
                            ->where('canonical_reference_id', $target->canonicalReferenceId)
                            ->where('channel', $target->channel);
                    });
            })
            ->first();

        if (! $row instanceof stdClass) {
            $this->denyIfForeignTargetIdExists($snapshot->workspaceId, $target->id);
            throw new InvalidArgumentException('Campaign target identity conflicts with existing state.');
        }

        $stored = $this->hydrateTarget($row);
        $this->assertTargetReplay($stored, $target);
    }

    private function appendEventInternal(CampaignEvent $event): CampaignEvent
    {
        $this->assertCampaignScope($event->workspaceId, $event->campaignId);

        if ($event->snapshotId !== null) {
            $snapshot = $this->database->connection()->table('campaign_snapshots')
                ->where('workspace_id', $event->workspaceId)
                ->where('id', $event->snapshotId)
                ->first();

            if (! $snapshot instanceof stdClass || (string) $snapshot->campaign_id !== $event->campaignId) {
                $this->denyIfForeignSnapshotIdExists($event->workspaceId, $event->snapshotId);
                throw new InvalidArgumentException('Campaign event snapshot does not belong to the campaign.');
            }
        }

        $existing = $this->eventByIdempotency($event->workspaceId, $event->idempotencyKey, true);
        if ($existing instanceof stdClass) {
            $stored = $this->hydrateEvent($existing);
            $this->assertEventReplay($stored, $event);

            return $stored;
        }

        $this->denyIfForeignEventIdExists($event->workspaceId, $event->id);

        $inserted = $this->database->connection()->table('campaign_events')->insertOrIgnore([
            'id' => $event->id,
            'workspace_id' => $event->workspaceId,
            'campaign_id' => $event->campaignId,
            'snapshot_id' => $event->snapshotId,
            'event_type' => $event->type,
            'actor_id' => $event->actorId,
            'reason' => $event->reason,
            'from_status' => $event->fromStatus?->value,
            'to_status' => $event->toStatus?->value,
            'evidence' => $this->encode($event->evidence),
            'idempotency_key' => $event->idempotencyKey,
            'occurred_at' => $event->occurredAt,
        ]);

        if ($inserted !== 1) {
            $winner = $this->eventByIdempotency($event->workspaceId, $event->idempotencyKey, true)
                ?? $this->database->connection()->table('campaign_events')
                    ->where('workspace_id', $event->workspaceId)
                    ->where('id', $event->id)
                    ->first();

            if (! $winner instanceof stdClass) {
                $this->denyIfForeignEventIdExists($event->workspaceId, $event->id);
                throw new InvalidArgumentException('Campaign event identity or idempotency key conflicts with existing state.');
            }

            $stored = $this->hydrateEvent($winner);
            $this->assertEventReplay($stored, $event);

            return $stored;
        }

        return $event;
    }

    private function assertCreationEvent(Campaign $campaign, CampaignEvent $event): void
    {
        if (
            $campaign->status !== CampaignStatus::Draft
            || $campaign->stateVersion !== 1
            || $event->type !== CampaignEvent::CREATED
            || $event->workspaceId !== $campaign->workspaceId
            || $event->campaignId !== $campaign->id
            || $event->snapshotId !== null
            || $event->fromStatus !== null
            || $event->toStatus !== CampaignStatus::Draft
            || $event->occurredAt < $campaign->createdAt
        ) {
            throw new InvalidArgumentException('Campaign creation event does not match the campaign draft state.');
        }
    }

    private function assertTransition(Campaign $stored, Campaign $next, CampaignEvent $event): void
    {
        if (
            $stored->id !== $next->id
            || $stored->workspaceId !== $next->workspaceId
            || $stored->name !== $next->name
            || $stored->idempotencyKey !== $next->idempotencyKey
            || $stored->createdByActorId !== $next->createdByActorId
            || ! self::sameMoment($stored->createdAt, $next->createdAt)
            || $next->stateVersion !== $stored->stateVersion + 1
            || $next->updatedAt === null
            || $event->type !== CampaignEvent::LIFECYCLE_TRANSITIONED
            || $event->workspaceId !== $next->workspaceId
            || $event->campaignId !== $next->id
            || $event->snapshotId !== null
            || $event->fromStatus !== $stored->status
            || $event->toStatus !== $next->status
            || $event->occurredAt < $next->updatedAt
        ) {
            throw new InvalidArgumentException('Campaign transition event or candidate state is inconsistent.');
        }
    }

    private function assertSnapshotEvent(CampaignSnapshot $snapshot, CampaignEvent $event): void
    {
        if (
            $event->type !== CampaignEvent::SNAPSHOT_CREATED
            || $event->workspaceId !== $snapshot->workspaceId
            || $event->campaignId !== $snapshot->campaignId
            || $event->snapshotId !== $snapshot->id
            || $event->occurredAt < $snapshot->createdAt
        ) {
            throw new InvalidArgumentException('Campaign snapshot event does not match the immutable snapshot.');
        }
    }

    private function assertApprovalEvent(CampaignApprovalDecision $decision, CampaignEvent $event): void
    {
        if (
            $event->type !== CampaignEvent::APPROVAL_RECORDED
            || $event->workspaceId !== $decision->workspaceId
            || $event->campaignId !== $decision->campaignId
            || $event->snapshotId !== $decision->snapshotId
            || $event->actorId !== $decision->actorId
            || ! self::sameMoment($event->occurredAt, $decision->occurredAt)
        ) {
            throw new InvalidArgumentException('Campaign approval event does not match the immutable decision.');
        }
    }

    private function assertSnapshotLineage(CampaignSnapshot $snapshot): void
    {
        if ($snapshot->parentSnapshotId === null) {
            if ($snapshot->versionNumber !== 1) {
                throw new InvalidArgumentException('Initial campaign snapshot must use version number 1.');
            }

            return;
        }

        $parent = $this->database->connection()->table('campaign_snapshots')
            ->where('workspace_id', $snapshot->workspaceId)
            ->where('id', $snapshot->parentSnapshotId)
            ->first();

        if (! $parent instanceof stdClass) {
            $this->denyIfForeignSnapshotIdExists($snapshot->workspaceId, $snapshot->parentSnapshotId);
            throw new InvalidArgumentException('Campaign snapshot parent does not exist in this workspace.');
        }

        if (
            (string) $parent->campaign_id !== $snapshot->campaignId
            || (int) $parent->version_number + 1 !== $snapshot->versionNumber
            || new DateTimeImmutable((string) $parent->created_at) > $snapshot->createdAt
        ) {
            throw new InvalidArgumentException('Campaign snapshot lineage must remain in one campaign and advance exactly one version.');
        }
    }

    private function assertSnapshotReferences(CampaignSnapshot $snapshot): void
    {
        $this->assertWorkspaceRow('content_versions', $snapshot->workspaceId, $snapshot->contentVersionId, 'Campaign content version');

        if ($snapshot->templateVersionId !== null) {
            $this->assertWorkspaceRow('content_template_versions', $snapshot->workspaceId, $snapshot->templateVersionId, 'Campaign template version');
        }

        foreach ($snapshot->componentVersionIds as $componentVersionId) {
            $this->assertWorkspaceRow('reusable_component_versions', $snapshot->workspaceId, $componentVersionId, 'Campaign component version');
        }

        foreach ($snapshot->assetReferenceIds as $assetReferenceId) {
            $exists = $this->database->connection()->table('asset_originals')
                ->where('workspace_id', $snapshot->workspaceId)
                ->where('id', $assetReferenceId)
                ->exists()
                || $this->database->connection()->table('asset_variants')
                    ->where('workspace_id', $snapshot->workspaceId)
                    ->where('id', $assetReferenceId)
                    ->exists();

            if (! $exists) {
                $foreign = $this->database->connection()->table('asset_originals')
                    ->where('id', $assetReferenceId)
                    ->where('workspace_id', '!=', $snapshot->workspaceId)
                    ->exists()
                    || $this->database->connection()->table('asset_variants')
                        ->where('id', $assetReferenceId)
                        ->where('workspace_id', '!=', $snapshot->workspaceId)
                        ->exists();

                if ($foreign) {
                    throw new AuthorizationException('Campaign asset reference access denied.');
                }

                throw new InvalidArgumentException('Campaign asset reference does not exist in this workspace.');
            }
        }

        foreach ($snapshot->capabilityEvidenceIds as $capabilityEvidenceId) {
            $this->assertCapability($snapshot->workspaceId, $capabilityEvidenceId);
        }

        if ($snapshot->brandReference !== []) {
            $brandId = $snapshot->brandReference['brand_id'] ?? null;
            $brandVersionId = $snapshot->brandReference['brand_version_id'] ?? null;

            if (! is_string($brandId) || ! is_string($brandVersionId)) {
                throw new InvalidArgumentException('Campaign brand reference must pin brand_id and brand_version_id.');
            }

            $this->assertWorkspaceRow('brands', $snapshot->workspaceId, $brandId, 'Campaign brand');
        }
    }

    private function assertTargetReference(string $workspaceId, CampaignTargetBinding $target): void
    {
        if ($target->workspaceId !== $workspaceId) {
            throw new AuthorizationException('Campaign target workspace access denied.');
        }

        $table = match ($target->kind) {
            CampaignTargetKind::ProviderConnection => 'provider_connections',
            CampaignTargetKind::ContactIdentity => 'contact_identities',
            CampaignTargetKind::Contact => 'contacts',
            CampaignTargetKind::ContactList => 'contact_lists',
            CampaignTargetKind::Tag => 'tags',
        };

        $this->assertWorkspaceRow($table, $workspaceId, $target->canonicalReferenceId, 'Campaign target');

        if (in_array($target->kind, [CampaignTargetKind::ContactList, CampaignTargetKind::Tag], true)) {
            $this->assertMaterializedRecipientSet($workspaceId, $target);

            return;
        }

        if ($target->kind !== CampaignTargetKind::ProviderConnection) {
            return;
        }

        $connection = $this->database->connection()->table('provider_connections')
            ->where('workspace_id', $workspaceId)
            ->where('id', $target->providerConnectionId)
            ->first();

        if (! $connection instanceof stdClass) {
            throw new AuthorizationException('Campaign provider connection access denied.');
        }

        if ($target->capabilityEvidenceId === null) {
            return;
        }

        $capability = $this->database->connection()->table('provider_capabilities')
            ->where('workspace_id', $workspaceId)
            ->where('id', $target->capabilityEvidenceId)
            ->first();

        if (
            ! $capability instanceof stdClass
            || (string) $capability->provider_id !== (string) $connection->provider_id
            || ($capability->connection_id !== null && (string) $capability->connection_id !== $target->providerConnectionId)
        ) {
            throw new AuthorizationException('Campaign capability evidence does not authorize the provider target.');
        }
    }

    private function assertMaterializedRecipientSet(
        string $workspaceId,
        CampaignTargetBinding $target,
    ): void {
        /** @var list<string> $materializedContactIds */
        $materializedContactIds = $target->metadata['materialized_contact_ids'];

        foreach ($materializedContactIds as $contactId) {
            $this->assertWorkspaceRow(
                'contacts',
                $workspaceId,
                $contactId,
                'Campaign materialized target contact',
            );
        }

        [$membershipTable, $targetColumn] = match ($target->kind) {
            CampaignTargetKind::ContactList => ['contact_list_memberships', 'list_id'],
            CampaignTargetKind::Tag => ['contact_tag_assignments', 'tag_id'],
            default => throw new InvalidArgumentException('Campaign materialized recipient validation requires list/tag target.'),
        };

        $canonicalContactIds = $this->database->connection()->table($membershipTable)
            ->where('workspace_id', $workspaceId)
            ->where($targetColumn, $target->canonicalReferenceId)
            ->pluck('contact_id')
            ->map(static fn (mixed $contactId): string => (string) $contactId)
            ->all();

        $expectedContactIds = $materializedContactIds;
        sort($canonicalContactIds, SORT_STRING);
        sort($expectedContactIds, SORT_STRING);

        if ($canonicalContactIds !== $expectedContactIds) {
            throw new InvalidArgumentException('Campaign materialized target set does not match canonical membership.');
        }
    }

    private function assertCapability(string $workspaceId, string $capabilityId): void
    {
        $this->assertWorkspaceRow('provider_capabilities', $workspaceId, $capabilityId, 'Campaign capability evidence');
    }

    private function assertWorkspaceRow(string $table, string $workspaceId, string $id, string $label): void
    {
        if ($this->database->connection()->table($table)
            ->where('workspace_id', $workspaceId)
            ->where('id', $id)
            ->exists()) {
            return;
        }

        if ($this->database->connection()->table($table)
            ->where('id', $id)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException("{$label} access denied.");
        }

        throw new InvalidArgumentException("{$label} does not exist in this workspace.");
    }

    private function assertCampaignScope(string $workspaceId, string $campaignId): void
    {
        if ($this->database->connection()->table('campaigns')
            ->where('workspace_id', $workspaceId)
            ->where('id', $campaignId)
            ->exists()) {
            return;
        }

        $this->denyIfForeignCampaignIdExists($workspaceId, $campaignId);
        throw new InvalidArgumentException('Campaign does not exist in this workspace.');
    }

    private function lockCampaign(string $workspaceId, string $campaignId): stdClass
    {
        $row = $this->database->connection()->table('campaigns')
            ->where('workspace_id', $workspaceId)
            ->where('id', $campaignId)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof stdClass) {
            $this->denyIfForeignCampaignIdExists($workspaceId, $campaignId);
            throw new InvalidArgumentException('Campaign does not exist in this workspace.');
        }

        return $row;
    }

    private function campaignByIdempotency(string $workspaceId, string $key, bool $lock): ?stdClass
    {
        $query = $this->database->connection()->table('campaigns')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $key);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function snapshotByIdempotency(string $workspaceId, string $key, bool $lock): ?stdClass
    {
        $query = $this->database->connection()->table('campaign_snapshots')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $key);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function approvalByIdempotency(string $workspaceId, string $key, bool $lock): ?stdClass
    {
        $query = $this->database->connection()->table('campaign_approval_decisions')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $key);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function eventByIdempotency(string $workspaceId, string $key, bool $lock): ?stdClass
    {
        $query = $this->database->connection()->table('campaign_events')
            ->where('workspace_id', $workspaceId)
            ->where('idempotency_key', $key);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function hydrateCampaign(stdClass $row): Campaign
    {
        return new Campaign(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            name: (string) $row->name,
            status: CampaignStatus::from((string) $row->status),
            stateVersion: (int) $row->state_version,
            idempotencyKey: (string) $row->idempotency_key,
            createdByActorId: (string) $row->created_by_actor_id,
            createdAt: new DateTimeImmutable((string) $row->created_at),
            updatedAt: $this->date($row->updated_at),
        );
    }

    private function hydrateSnapshot(stdClass $row): CampaignSnapshot
    {
        $targets = $this->database->connection()->table('campaign_targets')
            ->where('workspace_id', (string) $row->workspace_id)
            ->where('snapshot_id', (string) $row->id)
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $target): CampaignTargetBinding => $this->hydrateTarget($target))
            ->values()
            ->all();

        return new CampaignSnapshot(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            campaignId: (string) $row->campaign_id,
            parentSnapshotId: $row->parent_snapshot_id === null ? null : (string) $row->parent_snapshot_id,
            versionNumber: (int) $row->version_number,
            schemaVersion: (int) $row->schema_version,
            contentVersionId: (string) $row->content_version_id,
            templateVersionId: $row->template_version_id === null ? null : (string) $row->template_version_id,
            componentVersionIds: $this->decodeList((string) $row->component_version_ids),
            assetReferenceIds: $this->decodeList((string) $row->asset_reference_ids),
            capabilityEvidenceIds: $this->decodeList((string) $row->capability_evidence_ids),
            brandReference: $this->decodeMap((string) $row->brand_reference),
            intendedExecution: $this->decodeMap((string) $row->intended_execution),
            targets: $targets,
            targetSetHash: (string) $row->target_set_hash,
            snapshotHash: (string) $row->snapshot_hash,
            idempotencyKey: (string) $row->idempotency_key,
            createdByActorId: (string) $row->created_by_actor_id,
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }

    private function hydrateTarget(stdClass $row): CampaignTargetBinding
    {
        return new CampaignTargetBinding(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            kind: CampaignTargetKind::from((string) $row->kind),
            canonicalReferenceId: (string) $row->canonical_reference_id,
            channel: (string) $row->channel,
            providerConnectionId: $row->provider_connection_id === null ? null : (string) $row->provider_connection_id,
            capabilityEvidenceId: $row->capability_evidence_id === null ? null : (string) $row->capability_evidence_id,
            metadata: $this->decodeMap((string) $row->metadata),
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }

    private function hydrateApproval(stdClass $row): CampaignApprovalDecision
    {
        return new CampaignApprovalDecision(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            campaignId: (string) $row->campaign_id,
            snapshotId: (string) $row->snapshot_id,
            targetSetHash: (string) $row->target_set_hash,
            outcome: CampaignApprovalOutcome::from((string) $row->outcome),
            actorId: (string) $row->actor_id,
            actorRole: (string) $row->actor_role,
            reason: $row->reason === null ? null : (string) $row->reason,
            capabilityEvidenceIds: $this->decodeList((string) $row->capability_evidence_ids),
            supersedesDecisionId: $row->supersedes_decision_id === null ? null : (string) $row->supersedes_decision_id,
            expiresAt: $this->date($row->expires_at),
            idempotencyKey: (string) $row->idempotency_key,
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
        );
    }

    private function hydrateEvent(stdClass $row): CampaignEvent
    {
        return new CampaignEvent(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            campaignId: (string) $row->campaign_id,
            snapshotId: $row->snapshot_id === null ? null : (string) $row->snapshot_id,
            type: (string) $row->event_type,
            actorId: (string) $row->actor_id,
            reason: $row->reason === null ? null : (string) $row->reason,
            fromStatus: $row->from_status === null ? null : CampaignStatus::from((string) $row->from_status),
            toStatus: $row->to_status === null ? null : CampaignStatus::from((string) $row->to_status),
            evidence: $this->decodeMap((string) $row->evidence),
            idempotencyKey: (string) $row->idempotency_key,
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
        );
    }

    private function assertCampaignReplay(Campaign $stored, Campaign $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->name !== $candidate->name
            || $stored->status !== $candidate->status
            || $stored->stateVersion !== $candidate->stateVersion
            || $stored->idempotencyKey !== $candidate->idempotencyKey
            || $stored->createdByActorId !== $candidate->createdByActorId
            || ! self::sameMoment($stored->createdAt, $candidate->createdAt)
            || ! self::sameMoment($stored->updatedAt, $candidate->updatedAt)
        ) {
            throw new InvalidArgumentException('Campaign replay conflicts with persisted state.');
        }
    }

    private function assertSnapshotReplay(CampaignSnapshot $stored, CampaignSnapshot $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->campaignId !== $candidate->campaignId
            || $stored->idempotencyKey !== $candidate->idempotencyKey
            || $stored->createdByActorId !== $candidate->createdByActorId
            || $stored->snapshotHash !== $candidate->snapshotHash
            || $stored->targetSetHash !== $candidate->targetSetHash
            || ! self::sameMoment($stored->createdAt, $candidate->createdAt)
            || $this->targetIdentityPayloads($stored->targets) !== $this->targetIdentityPayloads($candidate->targets)
        ) {
            throw new InvalidArgumentException('Campaign snapshot replay conflicts with persisted immutable state.');
        }
    }

    private function assertTargetReplay(CampaignTargetBinding $stored, CampaignTargetBinding $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->canonicalPayload() !== $candidate->canonicalPayload()
            || ! self::sameMoment($stored->createdAt, $candidate->createdAt)
        ) {
            throw new InvalidArgumentException('Campaign target replay conflicts with persisted immutable state.');
        }
    }

    private function assertApprovalReplay(CampaignApprovalDecision $stored, CampaignApprovalDecision $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->campaignId !== $candidate->campaignId
            || $stored->snapshotId !== $candidate->snapshotId
            || $stored->targetSetHash !== $candidate->targetSetHash
            || $stored->outcome !== $candidate->outcome
            || $stored->actorId !== $candidate->actorId
            || $stored->actorRole !== $candidate->actorRole
            || $stored->reason !== $candidate->reason
            || $stored->capabilityEvidenceIds !== $candidate->capabilityEvidenceIds
            || $stored->supersedesDecisionId !== $candidate->supersedesDecisionId
            || ! self::sameMoment($stored->expiresAt, $candidate->expiresAt)
            || $stored->idempotencyKey !== $candidate->idempotencyKey
            || ! self::sameMoment($stored->occurredAt, $candidate->occurredAt)
        ) {
            throw new InvalidArgumentException('Campaign approval replay conflicts with persisted immutable state.');
        }
    }

    private function assertEventReplay(CampaignEvent $stored, CampaignEvent $candidate): void
    {
        if (
            $stored->id !== $candidate->id
            || $stored->workspaceId !== $candidate->workspaceId
            || $stored->campaignId !== $candidate->campaignId
            || $stored->snapshotId !== $candidate->snapshotId
            || $stored->type !== $candidate->type
            || $stored->actorId !== $candidate->actorId
            || $stored->reason !== $candidate->reason
            || $stored->fromStatus !== $candidate->fromStatus
            || $stored->toStatus !== $candidate->toStatus
            || $stored->evidence !== $candidate->evidence
            || $stored->idempotencyKey !== $candidate->idempotencyKey
            || ! self::sameMoment($stored->occurredAt, $candidate->occurredAt)
        ) {
            throw new InvalidArgumentException('Campaign event replay conflicts with persisted immutable state.');
        }
    }

    /** @param list<CampaignTargetBinding> $targets
     * @return list<array<string, mixed>>
     */
    private function targetIdentityPayloads(array $targets): array
    {
        $payloads = array_map(
            static fn (CampaignTargetBinding $target): array => [
                'id' => $target->id,
                'payload' => $target->canonicalPayload(),
                'created_at' => $target->createdAt->format(DATE_ATOM),
            ],
            $targets,
        );

        usort($payloads, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $payloads;
    }

    private function denyIfForeignCampaignIdExists(string $workspaceId, string $campaignId): void
    {
        if ($this->database->connection()->table('campaigns')
            ->where('id', $campaignId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign access denied.');
        }
    }

    private function denyIfForeignSnapshotIdExists(string $workspaceId, string $snapshotId): void
    {
        if ($this->database->connection()->table('campaign_snapshots')
            ->where('id', $snapshotId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign snapshot access denied.');
        }
    }

    private function denyIfForeignTargetIdExists(string $workspaceId, string $targetId): void
    {
        if ($this->database->connection()->table('campaign_targets')
            ->where('id', $targetId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign target access denied.');
        }
    }

    private function denyIfForeignApprovalIdExists(string $workspaceId, string $approvalId): void
    {
        if ($this->database->connection()->table('campaign_approval_decisions')
            ->where('id', $approvalId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign approval access denied.');
        }
    }

    private function denyIfForeignEventIdExists(string $workspaceId, string $eventId): void
    {
        if ($this->database->connection()->table('campaign_events')
            ->where('id', $eventId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            throw new AuthorizationException('Campaign event access denied.');
        }
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Campaign persistence payload is not JSON-encodable.', previous: $exception);
        }
    }

    /** @return list<string> */
    private function decodeList(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Persisted campaign list JSON is invalid.', previous: $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('Persisted campaign list JSON must be a list.');
        }

        foreach ($decoded as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Persisted campaign identifier list contains a non-string value.');
            }
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function decodeMap(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Persisted campaign JSON is invalid.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Persisted campaign JSON must decode to an array.');
        }

        return $decoded;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }

    private static function sameMoment(?DateTimeImmutable $left, ?DateTimeImmutable $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return $left->format('U.u') === $right->format('U.u');
    }
}
