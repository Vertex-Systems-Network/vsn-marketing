<?php

use App\Modules\Publishing\Domain\Campaign\Campaign;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalDecision;
use App\Modules\Publishing\Domain\Campaign\CampaignApprovalOutcome;
use App\Modules\Publishing\Domain\Campaign\CampaignEvent;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignStatus;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetBinding;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetKind;
use DateTimeImmutable;
use InvalidArgumentException;

function task0038DomainCampaign(): Campaign
{
    return Campaign::draft(
        id: 'campaign-1',
        workspaceId: 'workspace-1',
        name: 'Launch campaign',
        idempotencyKey: 'campaign-create-1',
        createdByActorId: 'author-1',
        createdAt: new DateTimeImmutable('2026-09-21T16:00:00+00:00'),
    );
}

function task0038DomainTarget(
    string $id,
    CampaignTargetKind $kind,
    string $reference,
    string $channel,
): CampaignTargetBinding {
    return new CampaignTargetBinding(
        id: $id,
        workspaceId: 'workspace-1',
        kind: $kind,
        canonicalReferenceId: $reference,
        channel: $channel,
        providerConnectionId: $kind === CampaignTargetKind::ProviderConnection ? $reference : null,
        capabilityEvidenceId: null,
        metadata: in_array($kind, [CampaignTargetKind::ContactList, CampaignTargetKind::Tag], true)
            ? ['source' => 'task0038-unit', 'materialized_contact_ids' => ['contact-1']]
            : ['source' => 'task0038-unit'],
        createdAt: new DateTimeImmutable('2026-09-21T16:01:00+00:00'),
    );
}

it('enforces deterministic campaign lifecycle transitions and optimistic state versions', function () {
    $draft = task0038DomainCampaign();
    $review = $draft->transitionTo(CampaignStatus::Review, new DateTimeImmutable('2026-09-21T16:02:00+00:00'));
    $needsApproval = $review->transitionTo(CampaignStatus::NeedsApproval, new DateTimeImmutable('2026-09-21T16:03:00+00:00'));
    $approved = $needsApproval->transitionTo(CampaignStatus::Approved, new DateTimeImmutable('2026-09-21T16:04:00+00:00'));
    $ready = $approved->transitionTo(CampaignStatus::Ready, new DateTimeImmutable('2026-09-21T16:05:00+00:00'));

    expect($draft->stateVersion)->toBe(1)
        ->and($review->stateVersion)->toBe(2)
        ->and($needsApproval->stateVersion)->toBe(3)
        ->and($approved->stateVersion)->toBe(4)
        ->and($ready->stateVersion)->toBe(5)
        ->and($draft->status)->toBe(CampaignStatus::Draft)
        ->and($ready->status)->toBe(CampaignStatus::Ready);

    expect(fn () => $draft->transitionTo(
        CampaignStatus::Approved,
        new DateTimeImmutable('2026-09-21T16:02:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'Invalid campaign lifecycle transition');

    expect(fn () => $review->transitionTo(
        CampaignStatus::Draft,
        new DateTimeImmutable('2026-09-21T15:59:59+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'time must not move backwards');
});

it('builds order-independent immutable campaign snapshot hashes from canonical target identities', function () {
    $contact = task0038DomainTarget('target-contact', CampaignTargetKind::Contact, 'contact-1', 'email');
    $list = task0038DomainTarget('target-list', CampaignTargetKind::ContactList, 'list-1', 'email');

    $first = CampaignSnapshot::create(
        id: 'snapshot-1',
        workspaceId: 'workspace-1',
        campaignId: 'campaign-1',
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: 'content-v1',
        templateVersionId: 'template-v1',
        componentVersionIds: ['component-v2', 'component-v1'],
        assetReferenceIds: ['asset-b', 'asset-a'],
        capabilityEvidenceIds: ['cap-b', 'cap-a'],
        brandReference: ['brand_id' => 'brand-1', 'brand_version_id' => 'brand-v1'],
        intendedExecution: ['mode' => 'fixed_instant', 'timezone' => 'UTC', 'at' => '2026-09-22T09:00:00Z'],
        targets: [$contact, $list],
        idempotencyKey: 'snapshot-create-1',
        createdByActorId: 'author-1',
        createdAt: new DateTimeImmutable('2026-09-21T16:05:00+00:00'),
    );

    $second = CampaignSnapshot::create(
        id: 'snapshot-2',
        workspaceId: 'workspace-1',
        campaignId: 'campaign-1',
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: 'content-v1',
        templateVersionId: 'template-v1',
        componentVersionIds: ['component-v1', 'component-v2'],
        assetReferenceIds: ['asset-a', 'asset-b'],
        capabilityEvidenceIds: ['cap-a', 'cap-b'],
        brandReference: ['brand_version_id' => 'brand-v1', 'brand_id' => 'brand-1'],
        intendedExecution: ['at' => '2026-09-22T09:00:00Z', 'timezone' => 'UTC', 'mode' => 'fixed_instant'],
        targets: [$list, $contact],
        idempotencyKey: 'snapshot-create-2',
        createdByActorId: 'author-1',
        createdAt: new DateTimeImmutable('2026-09-21T16:06:00+00:00'),
    );

    expect($first->targetSetHash)->toBe($second->targetSetHash)
        ->and($first->snapshotHash)->toBe($second->snapshotHash)
        ->and($first->snapshotHash)->toMatch('/^[0-9a-f]{64}$/');
});

it('canonicalizes materialized list recipient ordering into one stable target fingerprint', function () {
    $first = new CampaignTargetBinding(
        id: 'list-target-a',
        workspaceId: 'workspace-1',
        kind: CampaignTargetKind::ContactList,
        canonicalReferenceId: 'list-1',
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [
            'source' => 'task0038-unit',
            'materialized_contact_ids' => ['contact-2', 'contact-1'],
        ],
        createdAt: new DateTimeImmutable('2026-09-21T16:01:00+00:00'),
    );
    $second = new CampaignTargetBinding(
        id: 'list-target-b',
        workspaceId: 'workspace-1',
        kind: CampaignTargetKind::ContactList,
        canonicalReferenceId: 'list-1',
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [
            'materialized_contact_ids' => ['contact-1', 'contact-2'],
            'source' => 'task0038-unit',
        ],
        createdAt: new DateTimeImmutable('2026-09-21T16:02:00+00:00'),
    );

    expect($first->fingerprint())->toBe($second->fingerprint());
});

it('rejects provider-transient identifiers and provider routing on canonical recipient targets', function () {
    expect(fn () => new CampaignTargetBinding(
        id: 'target-unsafe',
        workspaceId: 'workspace-1',
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: 'contact-1',
        channel: 'email',
        providerConnectionId: 'connection-1',
        capabilityEvidenceId: null,
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-21T16:01:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'cannot embed provider routing');

    expect(fn () => new CampaignTargetBinding(
        id: 'target-transient',
        workspaceId: 'workspace-1',
        kind: CampaignTargetKind::ProviderConnection,
        canonicalReferenceId: 'connection-1',
        channel: 'social',
        providerConnectionId: 'connection-1',
        capabilityEvidenceId: null,
        metadata: ['provider_post_id' => 'remote-123'],
        createdAt: new DateTimeImmutable('2026-09-21T16:01:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'transient provider campaign key is forbidden');
});

it('binds approvals to exact target-set hashes and requires explicit lineage for revocation', function () {
    $target = task0038DomainTarget('target-contact', CampaignTargetKind::Contact, 'contact-1', 'email');
    $snapshot = CampaignSnapshot::create(
        id: 'snapshot-1',
        workspaceId: 'workspace-1',
        campaignId: 'campaign-1',
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: 'content-v1',
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'none'],
        targets: [$target],
        idempotencyKey: 'snapshot-create-1',
        createdByActorId: 'author-1',
        createdAt: new DateTimeImmutable('2026-09-21T16:05:00+00:00'),
    );

    $approved = new CampaignApprovalDecision(
        id: 'approval-1',
        workspaceId: 'workspace-1',
        campaignId: 'campaign-1',
        snapshotId: $snapshot->id,
        targetSetHash: $snapshot->targetSetHash,
        outcome: CampaignApprovalOutcome::Approved,
        actorId: 'approver-1',
        actorRole: 'campaign-approver',
        reason: 'Reviewed exact snapshot',
        capabilityEvidenceIds: [],
        supersedesDecisionId: null,
        expiresAt: new DateTimeImmutable('2026-09-22T16:00:00+00:00'),
        idempotencyKey: 'approval-1',
        occurredAt: new DateTimeImmutable('2026-09-21T16:10:00+00:00'),
    );

    $event = CampaignEvent::approvalRecorded(
        decision: $approved,
        id: 'event-approval-1',
        evidence: ['snapshot_hash' => $snapshot->snapshotHash],
        idempotencyKey: 'event-approval-1',
    );

    expect($approved->targetSetHash)->toBe($snapshot->targetSetHash)
        ->and($event->snapshotId)->toBe($snapshot->id)
        ->and($event->type)->toBe(CampaignEvent::APPROVAL_RECORDED);

    expect(fn () => new CampaignApprovalDecision(
        id: 'approval-revoke',
        workspaceId: 'workspace-1',
        campaignId: 'campaign-1',
        snapshotId: $snapshot->id,
        targetSetHash: $snapshot->targetSetHash,
        outcome: CampaignApprovalOutcome::Revoked,
        actorId: 'approver-1',
        actorRole: 'campaign-approver',
        reason: 'Revoke',
        capabilityEvidenceIds: [],
        supersedesDecisionId: null,
        expiresAt: null,
        idempotencyKey: 'approval-revoke',
        occurredAt: new DateTimeImmutable('2026-09-21T16:11:00+00:00'),
    ))->toThrow(InvalidArgumentException::class, 'must reference the superseded decision');
});
