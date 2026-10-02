<?php

namespace App\Modules\Experiments\Application;

use App\Modules\Experiments\Domain\CampaignExperimentMatrix;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\OptimizationProposal;
use App\Modules\Experiments\Domain\OptimizationReceiptVerifier;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** A review-only proposal ledger. It never mutates campaign, experiment or delivery state. */
final readonly class OptimizationProposals
{
    public function __construct(
        private ExperimentAccess $access,
        private OptimizationReceiptVerifier $receipt,
        private int $maxCostMinor,
    ) {
        if ($maxCostMinor < 1) {
            throw new InvalidArgumentException('Optimization budget policy missing.');
        }
    }

    public function submit(TenantContext $actor, string $bindingId, OptimizationProposal $proposal): string
    {
        $this->permit($actor, PermissionCatalog::AI_EXECUTE);
        if ($proposal->costCeilingMinor > $this->maxCostMinor
            || ! $this->receipt->verified($actor, $proposal->traceId, $proposal->promptHash,
                $proposal->contextHash, $proposal->sourceIds, $proposal->actualCostMinor)) {
            throw new InvalidArgumentException('Independent AI receipt or budget denied.');
        }

        return DB::transaction(function () use ($actor, $bindingId, $proposal): string {
            $binding = $this->binding($actor, $bindingId);
            $this->validateCandidate($actor, $binding, $proposal->candidate);
            if ($binding->status !== 'ready') {
                throw new InvalidArgumentException('Stopped campaign binding rejects proposals.');
            }
            $id = (string) Str::uuid();
            DB::table('experiment_optimization_proposals')->insert([
                'id' => $id, 'workspace_id' => $actor->workspaceId, 'binding_id' => $bindingId,
                'binding_matrix_hash' => $binding->matrix_hash,
                'proposal_hash' => $proposal->fingerprint($actor->workspaceId, $bindingId, $binding->matrix_hash),
                'trace_id' => $proposal->traceId,
                'proposal' => json_encode($proposal->canonical(), JSON_THROW_ON_ERROR),
                'status' => 'proposed', 'created_by_actor_id' => $actor->actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        }, 3);
    }

    /** Deterministic independent evaluation of the frozen candidate. */
    public function evaluate(TenantContext $evaluator, string $proposalId): string
    {
        $this->permit($evaluator, PermissionCatalog::AI_APPROVE);

        return DB::transaction(function () use ($evaluator, $proposalId): string {
            $row = $this->row($evaluator, $proposalId, true);
            $binding = $this->binding($evaluator, $row->binding_id);
            $proposal = $this->verify($evaluator, $row, $binding);
            if ($row->status !== 'proposed' || $row->created_by_actor_id === $evaluator->actorId
                || $binding->status !== 'ready') {
                throw new InvalidArgumentException('Independent candidate evaluation denied.');
            }
            $this->validateCandidate($evaluator, $binding, $proposal->candidate);
            $decision = $proposal->risk === 'high' || $proposal->uncertainty === 'high' ? 'rejected' : 'evaluated';
            $hash = hash('sha256', json_encode([$row->proposal_hash, $binding->matrix_hash,
                $evaluator->actorId, $decision, 'policy.v1'], JSON_THROW_ON_ERROR));
            DB::table('experiment_optimization_proposals')->where('id', $proposalId)->where('workspace_id', $evaluator->workspaceId)
                ->update(['status' => $decision, 'evaluation_hash' => $hash,
                    'evaluated_by_actor_id' => $evaluator->actorId, 'updated_at' => now()]);

            return $hash;
        }, 3);
    }

    /** The promoted artifact is an offline reviewed draft. No live binding changes. */
    public function promote(TenantContext $reviewer, string $proposalId, string $expectedProposalHash, string $expectedEvaluationHash): array
    {
        $this->permit($reviewer, PermissionCatalog::AI_APPROVE);
        $this->permit($reviewer, PermissionCatalog::CAMPAIGN_APPROVE);

        DB::transaction(function () use ($reviewer, $proposalId, $expectedProposalHash, $expectedEvaluationHash): void {
            $row = $this->row($reviewer, $proposalId, true);
            $binding = $this->binding($reviewer, $row->binding_id);
            $proposal = $this->verify($reviewer, $row, $binding);
            if ($row->status !== 'evaluated' || $binding->status !== 'ready'
                || $row->proposal_hash !== $expectedProposalHash || $row->evaluation_hash !== $expectedEvaluationHash
                || $row->created_by_actor_id === $reviewer->actorId || $row->evaluated_by_actor_id === $reviewer->actorId
                || $row->evaluation_hash !== $this->evaluationHash($row, $binding, 'evaluated')
                || $proposal->risk === 'high' || $proposal->uncertainty === 'high') {
                throw new InvalidArgumentException('Exact independent optimization review denied.');
            }
            $this->validateCandidate($reviewer, $binding, $proposal->candidate);
            DB::table('experiment_optimization_proposals')->where('id', $proposalId)->where('workspace_id', $reviewer->workspaceId)
                ->update(['status' => 'reviewed_draft', 'reviewed_by_actor_id' => $reviewer->actorId, 'updated_at' => now()]);

        }, 3);

        return ['status' => 'reviewed_draft', 'candidate_sha256' => $expectedProposalHash,
            'evaluation_sha256' => $expectedEvaluationHash, 'workspace_id' => $reviewer->workspaceId,
            'proposal_id' => $proposalId, 'publication_authorized' => false];
    }

    public function rollback(TenantContext $reviewer, string $proposalId, string $expectedProposalHash): void
    {
        $this->permit($reviewer, PermissionCatalog::AI_APPROVE);
        $this->permit($reviewer, PermissionCatalog::CAMPAIGN_APPROVE);
        DB::transaction(function () use ($reviewer, $proposalId, $expectedProposalHash): void {
            $row = $this->row($reviewer, $proposalId, true);
            $binding = $this->binding($reviewer, $row->binding_id);
            $this->verify($reviewer, $row, $binding);
            if ($row->status !== 'reviewed_draft' || $row->proposal_hash !== $expectedProposalHash
                || $row->created_by_actor_id === $reviewer->actorId) {
                throw new InvalidArgumentException('Exact optimization rollback denied.');
            }
            DB::table('experiment_optimization_proposals')->where('id', $proposalId)->where('workspace_id', $reviewer->workspaceId)
                ->update(['status' => 'reverted', 'updated_at' => now()]);
        }, 3);
    }

    private function validateCandidate(TenantContext $actor, object $binding, CampaignExperimentMatrix $candidate): void
    {
        $original = new CampaignExperimentMatrix(json_decode($binding->variants, true, 16, JSON_THROW_ON_ERROR));
        $experiment = DB::table('experiments')->where('id', $binding->experiment_id)
            ->where('workspace_id', $actor->workspaceId)->where('brand_id', $actor->brandId)->first();
        $snapshot = DB::table('campaign_snapshots')->where('id', $binding->snapshot_id)->where('workspace_id', $actor->workspaceId)->first();
        $originalNames = array_keys($original->variants);
        $candidateNames = array_keys($candidate->variants);
        sort($originalNames);
        sort($candidateNames);
        if ($experiment === null || $experiment->status !== 'active' || $snapshot === null || $snapshot->snapshot_hash !== $binding->snapshot_hash
            || $experiment->plan_hash !== $binding->plan_hash || $original->fingerprint() !== $binding->matrix_hash
            || $candidateNames !== $originalNames || $candidate->variants[$experiment->control_variant]['content'] !== $snapshot->content_version_id) {
            throw new InvalidArgumentException('Proposal changes frozen plan, scope or control.');
        }
        foreach ($candidate->variants as $arm) {
            if (! DB::table('content_versions')->where('id', $arm['content'])->where('workspace_id', $actor->workspaceId)->exists()
                || ! DB::table('segment_definition_versions')->where('id', $arm['audience'])->where('workspace_id', $actor->workspaceId)->exists()) {
                throw new InvalidArgumentException('Proposal references foreign canonical candidate.');
            }
        }
    }

    private function verify(TenantContext $actor, object $row, object $binding): OptimizationProposal
    {
        $data = json_decode($row->proposal, true, 16, JSON_THROW_ON_ERROR);
        $proposal = new OptimizationProposal($data['trace_id'], $data['prompt_hash'], $data['context_hash'],
            $data['source_ids'], new CampaignExperimentMatrix($data['candidate']), $data['uncertainty'], $data['risk'],
            $data['cost_ceiling_minor'], $data['actual_cost_minor']);
        if ($data !== $proposal->canonical() || $proposal->traceId !== $row->trace_id
            || $binding->matrix_hash !== $row->binding_matrix_hash
            || $row->proposal_hash !== $proposal->fingerprint($actor->workspaceId, $row->binding_id, $binding->matrix_hash)) {
            throw new RuntimeException('Frozen optimization proposal integrity failed.');
        }

        return $proposal;
    }

    private function binding(TenantContext $actor, string $id): object
    {
        return DB::table('campaign_experiment_bindings')->where('id', $id)->where('workspace_id', $actor->workspaceId)->first()
            ?? throw new InvalidArgumentException('Optimization binding outside workspace.');
    }

    private function row(TenantContext $actor, string $id, bool $lock): object
    {
        $query = DB::table('experiment_optimization_proposals')->where('id', $id)->where('workspace_id', $actor->workspaceId);

        return ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw new InvalidArgumentException('Optimization proposal outside workspace.');
    }

    private function permit(TenantContext $actor, string $permission): void
    {
        if (! $this->access->allows($actor, $permission)) {
            throw new InvalidArgumentException('Optimization permission denied.');
        }
    }

    private function evaluationHash(object $row, object $binding, string $decision): string
    {
        return hash('sha256', json_encode([$row->proposal_hash, $binding->matrix_hash,
            $row->evaluated_by_actor_id, $decision, 'policy.v1'], JSON_THROW_ON_ERROR));
    }
}
