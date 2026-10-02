<?php

use App\Modules\Experiments\Application\CampaignExperiments;
use App\Modules\Experiments\Application\ExperimentAssignments;
use App\Modules\Experiments\Domain\CampaignExperimentMatrix;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAllocator;
use App\Modules\Experiments\Domain\ExperimentEligibility;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Experiments\Domain\ExposureVerifier;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function campaignExperimentFixture(bool $eligible = true): array
{
    $org = (string) Str::uuid();
    $workspace = (string) Str::uuid();
    $content = (string) Str::uuid();
    $document = (string) Str::uuid();
    $audience = (string) Str::uuid();
    $segment = (string) Str::uuid();
    $campaign = (string) Str::uuid();
    $snapshot = (string) Str::uuid();
    $owner = new TenantContext($org, $workspace, null, 'owner');
    $reviewer = new TenantContext($org, $workspace, null, 'reviewer');
    DB::table('organizations')->insert(['id' => $org, 'name' => 'Matrix', 'slug' => 'matrix-'.Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('workspaces')->insert(['id' => $workspace, 'organization_id' => $org, 'name' => 'Matrix', 'slug' => 'matrix-'.Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('content_documents')->insert(['id' => $document, 'workspace_id' => $workspace, 'name' => 'Doc', 'lifecycle' => 'draft', 'created_by_actor_id' => 'owner', 'audit_provenance' => '{}', 'created_at' => now()]);
    DB::table('content_versions')->insert(['id' => $content, 'workspace_id' => $workspace, 'document_id' => $document,
        'version_number' => 1, 'schema_version' => 1, 'status' => 'draft', 'canonical_tree' => '{}', 'audit_provenance' => '{}',
        'idempotency_key' => (string) Str::uuid(), 'created_by_actor_id' => 'owner', 'created_at' => now()]);
    DB::table('segment_definitions')->insert(['id' => $segment, 'workspace_id' => $workspace, 'name' => 'Audience', 'created_by_actor_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('segment_definition_versions')->insert(['id' => $audience, 'workspace_id' => $workspace, 'definition_id' => $segment,
        'version_number' => 1, 'definition_ast' => '{}', 'definition_hash' => str_repeat('a', 64),
        'created_by_actor_id' => (string) Str::uuid(), 'created_at' => now()]);
    DB::table('campaigns')->insert(['id' => $campaign, 'workspace_id' => $workspace, 'name' => 'Test', 'status' => 'draft',
        'state_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'created_by_actor_id' => 'owner', 'created_at' => now()]);
    DB::table('campaign_snapshots')->insert(['id' => $snapshot, 'workspace_id' => $workspace, 'campaign_id' => $campaign,
        'version_number' => 1, 'schema_version' => 1, 'content_version_id' => $content,
        'component_version_ids' => '[]', 'asset_reference_ids' => '[]', 'capability_evidence_ids' => '[]',
        'brand_reference' => '{}', 'intended_execution' => '{}', 'target_set_hash' => str_repeat('b', 64),
        'snapshot_hash' => str_repeat('c', 64), 'idempotency_key' => (string) Str::uuid(), 'created_by_actor_id' => 'owner', 'created_at' => now()]);
    $access = new class implements ExperimentAccess
    {
        public function allows(TenantContext $actor, string $permission): bool
        {
            return in_array($permission, ['campaign.create', 'campaign.approve', 'campaign.read'], true);
        }
    };
    $eligibility = new class($eligible) implements ExperimentEligibility
    {
        public function __construct(private bool $eligible) {}

        public function allows(TenantContext $actor, string $unitKind, string $unitId): bool
        {
            return $this->eligible && $unitKind === 'contact' && $unitId !== 'suppressed';
        }
    };
    $verifier = new class implements ExposureVerifier
    {
        public function witnessed(TenantContext $actor, string $experimentId, string $assignmentId, string $variant, string $reference, DateTimeImmutable $at): bool
        {
            return str_starts_with($reference, 'campaign:');
        }
    };
    $assignments = new ExperimentAssignments(new ExperimentAllocator(str_repeat('k', 32)), $access, $verifier, $eligibility);
    $service = new CampaignExperiments($assignments, $access, $eligibility);
    $plan = new ExperimentPlan((string) Str::uuid(), $workspace, null, 'campaign-matrix', 'contact',
        ['control' => 5000, 'treatment' => 5000], 'control', null);
    $matrix = new CampaignExperimentMatrix([
        'control' => ['content' => $content, 'time' => '2026-10-03T09:00:00Z', 'audience' => $audience],
        'treatment' => ['content' => $content, 'time' => '2026-10-03T10:00:00Z', 'audience' => $audience],
    ]);
    $assignments->create($owner, $plan);
    $binding = $service->bind($owner, $plan->id, $snapshot, $matrix);
    $assignments->activate($reviewer, $plan->id);

    return [$owner, $reviewer, $plan, $binding, $service, $assignments];
}

it('keeps a sticky candidate, quarantines missing exposure and crossover, and stops after rollback', function () {
    [$owner, $reviewer, $plan, $binding, $service, $assignments] = campaignExperimentFixture();
    $candidate = $service->candidate($owner, $binding, 'contact-1');
    expect($service->candidate($owner, $binding, 'contact-1'))->toBe($candidate)
        ->and($candidate['content'])->toBeString();
    $at = new DateTimeImmutable('now +2 seconds');
    expect($service->admitOutcome($owner, $binding, $candidate['assignment_id'], $candidate['variant'], $candidate['reference'], 'event-1', $at))
        ->toBe('quarantined');
    $assignments->recordExposure($owner, $plan->id, $candidate['assignment_id'], $candidate['variant'], $candidate['reference'], new DateTimeImmutable('now'));
    expect($service->admitOutcome($owner, $binding, $candidate['assignment_id'], $candidate['variant'], $candidate['reference'], 'event-2', $at))
        ->toBe('admitted')
        ->and($service->admitOutcome($owner, $binding, $candidate['assignment_id'], $candidate['variant'], $candidate['reference'], 'event-2', $at))
        ->toBe('admitted')
        ->and($service->admitOutcome($owner, $binding, $candidate['assignment_id'], 'wrong', $candidate['reference'], 'event-3', $at))
        ->toBe('quarantined');
    $service->rollback($reviewer, $binding);
    expect(fn () => $service->candidate($owner, $binding, 'contact-2'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('campaign_experiment_outcomes')->where('state', 'admitted')->count())->toBe(1);
});

it('denies suppressed units and foreign workspace binding', function () {
    [$owner, , , $binding, $service] = campaignExperimentFixture();
    expect(fn () => $service->candidate($owner, $binding, 'suppressed'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->candidate(new TenantContext($owner->organizationId, (string) Str::uuid(), null, 'owner'), $binding, 'unit'))
        ->toThrow(InvalidArgumentException::class);
});
