<?php

use App\Modules\Publishing\Domain\Campaign\Campaign;
use App\Modules\Publishing\Domain\Campaign\CampaignEvent;
use App\Modules\Publishing\Domain\Campaign\CampaignSnapshot;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetBinding;
use App\Modules\Publishing\Domain\Campaign\CampaignTargetKind;
use App\Modules\Publishing\Infrastructure\Persistence\DatabaseCampaignRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function task0038SecurityWorkspace(string $suffix): string
{
    $organizationId = (string) Str::uuid();
    $workspaceId = (string) Str::uuid();
    $now = now();

    DB::table('organizations')->insert([
        'id' => $organizationId,
        'name' => 'Task0038 Security '.$suffix,
        'slug' => 'task0038-security-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'organization_id' => $organizationId,
        'name' => 'Task0038 Security Workspace '.$suffix,
        'slug' => 'task0038-security-workspace-'.$suffix,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $workspaceId;
}

function task0038SecurityContentVersion(string $workspaceId, string $suffix): string
{
    $documentId = (string) Str::uuid();
    $versionId = (string) Str::uuid();
    $at = new DateTimeImmutable('2026-09-21T19:00:00+00:00');

    DB::table('content_documents')->insert([
        'id' => $documentId,
        'workspace_id' => $workspaceId,
        'name' => 'Security content '.$suffix,
        'lifecycle' => 'active',
        'created_by_actor_id' => 'security-user',
        'audit_provenance' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => $at,
        'updated_at' => null,
    ]);

    DB::table('content_versions')->insert([
        'id' => $versionId,
        'workspace_id' => $workspaceId,
        'document_id' => $documentId,
        'parent_version_id' => null,
        'version_number' => 1,
        'schema_version' => 1,
        'status' => 'published',
        'canonical_tree' => json_encode(['schema_version' => 1, 'root' => []], JSON_THROW_ON_ERROR),
        'audit_provenance' => json_encode([], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'security-content-'.$suffix,
        'created_by_actor_id' => 'security-user',
        'created_at' => $at,
    ]);

    return $versionId;
}

it('fails closed on foreign campaign identity reads and immutable snapshot mutation', function () {
    $inside = task0038SecurityWorkspace('inside');
    $outside = task0038SecurityWorkspace('outside');
    $contentVersionId = task0038SecurityContentVersion($inside, 'inside');
    $contactId = (string) Str::uuid();
    $now = now();

    DB::table('contacts')->insert([
        'id' => $contactId,
        'workspace_id' => $inside,
        'brand_id' => null,
        'company_id' => null,
        'first_name' => 'Security',
        'last_name' => 'Target',
        'display_name' => 'Security Target',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $repository = app(DatabaseCampaignRepository::class);
    $at = new DateTimeImmutable('2026-09-21T19:05:00+00:00');
    $campaign = Campaign::draft(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        name: 'Security campaign',
        idempotencyKey: 'security-campaign',
        createdByActorId: 'security-user',
        createdAt: $at,
    );
    $repository->createCampaign(
        $campaign,
        CampaignEvent::created(
            $campaign,
            (string) Str::uuid(),
            'security-user',
            null,
            [],
            'security-campaign-created',
            $at,
        ),
    );

    expect(fn () => $repository->findCampaign($outside, $campaign->id))
        ->toThrow(AuthorizationException::class, 'Campaign access denied');

    $target = new CampaignTargetBinding(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        kind: CampaignTargetKind::Contact,
        canonicalReferenceId: $contactId,
        channel: 'email',
        providerConnectionId: null,
        capabilityEvidenceId: null,
        metadata: [],
        createdAt: new DateTimeImmutable('2026-09-21T19:06:00+00:00'),
    );
    $snapshot = CampaignSnapshot::create(
        id: (string) Str::uuid(),
        workspaceId: $inside,
        campaignId: $campaign->id,
        parentSnapshotId: null,
        versionNumber: 1,
        contentVersionId: $contentVersionId,
        templateVersionId: null,
        componentVersionIds: [],
        assetReferenceIds: [],
        capabilityEvidenceIds: [],
        brandReference: [],
        intendedExecution: ['mode' => 'none'],
        targets: [$target],
        idempotencyKey: 'security-snapshot',
        createdByActorId: 'security-user',
        createdAt: new DateTimeImmutable('2026-09-21T19:06:00+00:00'),
    );
    $repository->appendSnapshot(
        $snapshot,
        CampaignEvent::snapshotCreated(
            $snapshot,
            (string) Str::uuid(),
            'security-user',
            null,
            [],
            'security-snapshot-created',
            new DateTimeImmutable('2026-09-21T19:06:00+00:00'),
        ),
    );

    expect(fn () => DB::table('campaign_snapshots')
        ->where('id', $snapshot->id)
        ->update(['target_set_hash' => str_repeat('b', 64)]))
        ->toThrow(QueryException::class);
});
