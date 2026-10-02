<?php

use App\Modules\Experiments\Application\CampaignExperiments;
use App\Modules\Experiments\Application\ExperimentAnalysis;
use App\Modules\Experiments\Application\ExperimentAssignments;
use App\Modules\Experiments\Application\OptimizationProposals;
use App\Modules\Experiments\Domain\CampaignExperimentMatrix;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAllocator;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentEligibility;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Experiments\Domain\ExposureVerifier;
use App\Modules\Experiments\Domain\OptimizationProposal;
use App\Modules\Experiments\Domain\OptimizationReceiptVerifier;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
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
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->rollback(new TenantContext($owner->organizationId, (string) Str::uuid(), null, 'reviewer'), $binding))
        ->toThrow(InvalidArgumentException::class);
});

function optimizationFixture(bool $receipt = true, int $budget = 100): array
{
    [$owner, $reviewer, , $binding] = campaignExperimentFixture();
    $access = new class implements ExperimentAccess
    {
        public function allows(TenantContext $actor, string $permission): bool
        {
            return in_array($permission, ['ai.execute', 'ai.approve', 'campaign.approve'], true);
        }
    };
    $verifier = new class($receipt) implements OptimizationReceiptVerifier
    {
        public function __construct(private bool $verified) {}

        public function verified(TenantContext $actor, string $traceId, string $promptHash, string $contextHash, array $sourceIds, int $actualMinor): bool
        {
            return $this->verified && in_array($traceId, ['trace-1', 'trace-2'], true) && $sourceIds === ['source:1'] && $actualMinor === 20;
        }
    };
    $matrix = json_decode(DB::table('campaign_experiment_bindings')->where('id', $binding)->value('variants'), true, 16, JSON_THROW_ON_ERROR);
    $matrix['treatment']['time'] = '2026-10-04T10:00:00Z';
    $proposal = new OptimizationProposal('trace-1', str_repeat('a', 64), str_repeat('b', 64), ['source:1'],
        new CampaignExperimentMatrix($matrix), 'medium', 'low', 100, 20);

    return [$owner, $reviewer, new TenantContext($owner->organizationId, $owner->workspaceId, null, 'auditor'),
        $binding, new OptimizationProposals($access, $verifier, $budget), $proposal];
}

it('requires independent evaluation and exact review and preserves a reversible offline draft', function () {
    [$owner, $reviewer, $auditor, $binding, $service, $proposal] = optimizationFixture();
    $id = $service->submit($owner, $binding, $proposal);
    $replay = new OptimizationProposal($proposal->traceId, $proposal->promptHash, $proposal->contextHash,
        $proposal->sourceIds, $proposal->candidate, 'low', 'medium', $proposal->costCeilingMinor, $proposal->actualCostMinor);
    expect(fn () => $service->submit($owner, $binding, $replay))->toThrow(QueryException::class);
    expect(fn () => $service->evaluate($owner, $id))->toThrow(InvalidArgumentException::class);
    $report = $service->evaluate($auditor, $id);
    $hash = DB::table('experiment_optimization_proposals')->where('id', $id)->value('proposal_hash');
    expect(fn () => $service->promote($auditor, $id, $hash, $report))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->promote($owner, $id, $hash, $report))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->promote($reviewer, $id, str_repeat('f', 64), $report))->toThrow(InvalidArgumentException::class);
    $review = $service->promote($reviewer, $id, $hash, $report);
    expect($review['status'])->toBe('reviewed_draft')
        ->and($review['publication_authorized'])->toBeFalse()
        ->and(DB::table('campaign_experiment_bindings')->where('id', $binding)->value('status'))->toBe('ready');
    $service->rollback($reviewer, $id, $hash);
    expect(DB::table('experiment_optimization_proposals')->where('id', $id)->value('status'))->toBe('reverted')
        ->and(fn () => $service->promote($reviewer, $id, $hash, $report))->toThrow(InvalidArgumentException::class);
});

it('rejects high-risk model recommendations and corrupted candidate provenance', function () {
    [$owner, $reviewer, $auditor, $binding, $service, $proposal] = optimizationFixture();
    $high = new OptimizationProposal($proposal->traceId, $proposal->promptHash, $proposal->contextHash,
        $proposal->sourceIds, $proposal->candidate, 'high', 'high', $proposal->costCeilingMinor, $proposal->actualCostMinor);
    $id = $service->submit($owner, $binding, $high);
    $report = $service->evaluate($auditor, $id);
    $hash = DB::table('experiment_optimization_proposals')->where('id', $id)->value('proposal_hash');
    expect(DB::table('experiment_optimization_proposals')->where('id', $id)->value('status'))->toBe('rejected')
        ->and(fn () => $service->promote($reviewer, $id, $hash, $report))->toThrow(InvalidArgumentException::class);
    $normal = new OptimizationProposal('trace-2', $proposal->promptHash, $proposal->contextHash,
        $proposal->sourceIds, $proposal->candidate, $proposal->uncertainty, $proposal->risk,
        $proposal->costCeilingMinor, $proposal->actualCostMinor);
    $normalId = $service->submit($owner, $binding, $normal);
    DB::table('experiment_optimization_proposals')->where('id', $normalId)->update(['proposal_hash' => str_repeat('f', 64)]);
    expect(fn () => $service->evaluate($auditor, $normalId))->toThrow(RuntimeException::class);
});

it('denies fabricated receipt, budget overflow and foreign scope', function () {
    [$owner, , , $binding, $service, $proposal] = optimizationFixture(false);
    expect(fn () => $service->submit($owner, $binding, $proposal))->toThrow(InvalidArgumentException::class);
    [, , , , $limited] = optimizationFixture(true, 50);
    [$scopedOwner, , , $scopedBinding, $valid, $scopedProposal] = optimizationFixture();
    expect(fn () => $limited->submit($owner, $binding, $proposal))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $valid->submit(new TenantContext($scopedOwner->organizationId, (string) Str::uuid(), null, 'owner'), $scopedBinding, $scopedProposal))
        ->toThrow(InvalidArgumentException::class);
});

it('freezes and independently approves an offline analysis before assignment', function () {
    [$owner, $reviewer, $experiment, $binding, $campaign, $assignments] = campaignExperimentFixture();
    $access = new class implements ExperimentAccess
    {
        public function allows(TenantContext $actor, string $permission): bool
        {
            return in_array($permission, ['campaign.create', 'campaign.approve', 'campaign.read'], true);
        }
    };
    $analysis = new ExperimentAnalysis($access);
    $plan = new ExperimentAnalysisPlan($binding, 'contact',
        ['control' => 5000, 'treatment' => 5000], 'control', null, 0.05, 0.8, 0.5, 0.2,
        new DateTimeImmutable('now +1 day'));
    $id = $analysis->register($owner, $plan);
    expect(fn () => $analysis->approve($owner, $id, $plan->fingerprint()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $analysis->analyze($owner, $id, new DateTimeImmutable('now')))->toThrow(InvalidArgumentException::class);
    $analysis->approve($reviewer, $id, $plan->fingerprint());
    $pending = $analysis->analyze($owner, $id, new DateTimeImmutable('now'));
    expect($pending['status'])->toBe('pending')->and($pending['publication_authorized'])->toBeFalse()
        ->and($pending['evidence_kind'])->toBe('offline_admitted_outcome_event');
    $candidate = $campaign->candidate($owner, $binding, 'contact-1');
    expect(fn () => $analysis->register($owner, new ExperimentAnalysisPlan(
        $binding, 'contact', ['control' => 5000, 'treatment' => 5000], 'control', null,
        0.05, 0.8, 0.5, 0.2, new DateTimeImmutable('now +2 days'))))->toThrow(InvalidArgumentException::class);
    expect(fn () => $analysis->analyze($owner, $id, new DateTimeImmutable('now +2 days')))->toThrow(InvalidArgumentException::class);
    $report = $analysis->analyze($owner, $id, new DateTimeImmutable('now'));
    expect($report['status'])->toBe('invalid')->and($report['diagnostics']['missing_exposure'])->toBe(1);
    DB::table('experiment_assignments')->where('id', $candidate['assignment_id'])
        ->update(['assigned_at' => now()->addDays(3)]);
    $late = $analysis->analyze($owner, $id, new DateTimeImmutable('now'));
    expect($late['status'])->toBe('invalid')->and($late['diagnostics']['crossovers'])->toBeGreaterThan(0)
        ->and($late['diagnostics']['assigned']['control'] + $late['diagnostics']['assigned']['treatment'])->toBe(0);
    expect(fn () => $analysis->analyze(new TenantContext($owner->organizationId, (string) Str::uuid(), null, 'owner'), $id,
        new DateTimeImmutable('now')))->toThrow(InvalidArgumentException::class);
    DB::table('experiment_analysis_plans')->where('id', $id)->update(['plan_hash' => str_repeat('f', 64)]);
    expect(fn () => $analysis->analyze($owner, $id, new DateTimeImmutable('now')))->toThrow(RuntimeException::class);
});
