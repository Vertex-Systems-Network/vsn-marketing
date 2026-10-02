<?php

namespace App\Modules\Experiments\Application;

use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentStatistics;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Frozen, workspace-scoped offline analysis. No production performance or winner decision. */
final readonly class ExperimentAnalysis
{
    public function __construct(private ExperimentAccess $access) {}

    public function register(TenantContext $actor, ExperimentAnalysisPlan $plan): string
    {
        $this->permit($actor, PermissionCatalog::CAMPAIGN_CREATE);

        return DB::transaction(function () use ($actor, $plan): string {
            [$binding, $experiment] = $this->scope($actor, $plan->bindingId);
            $weights = json_decode($experiment->allocation, true, 16, JSON_THROW_ON_ERROR);
            ksort($weights);
            $given = $plan->weights;
            ksort($given);
            if ($binding->status !== 'ready' || $experiment->status !== 'active'
                || $plan->unitKind !== $experiment->unit_kind || $given !== $weights
                || $plan->control !== $experiment->control_variant || $plan->holdout !== $experiment->holdout_variant
                || $plan->horizonUtc <= new DateTimeImmutable('now') || $this->hasAssignments($actor, $experiment->id)) {
                throw new InvalidArgumentException('Analysis must be frozen before assignment against the active plan.');
            }
            $id = (string) Str::uuid();
            DB::table('experiment_analysis_plans')->insert([
                'id' => $id, 'workspace_id' => $actor->workspaceId, 'binding_id' => $binding->id,
                'plan' => json_encode($plan->canonical(), JSON_THROW_ON_ERROR), 'plan_hash' => $plan->fingerprint(),
                'status' => 'draft', 'created_by_actor_id' => $actor->actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        }, 3);
    }

    public function approve(TenantContext $reviewer, string $planId, string $expectedHash): void
    {
        $this->permit($reviewer, PermissionCatalog::CAMPAIGN_APPROVE);
        DB::transaction(function () use ($reviewer, $planId, $expectedHash): void {
            $row = $this->row($reviewer, $planId, true);
            $plan = $this->verify($row);
            [$binding, $experiment] = $this->scope($reviewer, $plan->bindingId);
            if ($row->status !== 'draft' || $row->created_by_actor_id === $reviewer->actorId
                || $row->plan_hash !== $expectedHash || $binding->status !== 'ready' || $experiment->status !== 'active'
                || $this->hasAssignments($reviewer, $experiment->id)) {
                throw new InvalidArgumentException('Independent pre-assignment analysis approval denied.');
            }
            DB::table('experiment_analysis_plans')->where('id', $row->id)->where('workspace_id', $reviewer->workspaceId)
                ->update(['status' => 'approved', 'approved_by_actor_id' => $reviewer->actorId, 'updated_at' => now()]);
        }, 3);
    }

    public function analyze(TenantContext $actor, string $planId, DateTimeImmutable $at): array
    {
        $this->permit($actor, PermissionCatalog::CAMPAIGN_READ);
        if ($at > new DateTimeImmutable('now +1 second')) {
            throw new InvalidArgumentException('Analysis observation time cannot be in the future.');
        }
        $row = $this->row($actor, $planId, false);
        $plan = $this->verify($row);
        [$binding, $experiment] = $this->scope($actor, $plan->bindingId);
        if ($row->status !== 'approved' || $binding->status !== 'ready' || $experiment->status !== 'active') {
            throw new InvalidArgumentException('Unapproved or stopped analysis denied.');
        }
        $weights = json_decode($experiment->allocation, true, 16, JSON_THROW_ON_ERROR);
        ksort($weights);
        $planned = $plan->weights;
        ksort($planned);
        if ($binding->plan_hash !== $experiment->plan_hash || $weights !== $planned
            || $plan->unitKind !== $experiment->unit_kind || $plan->control !== $experiment->control_variant
            || $plan->holdout !== $experiment->holdout_variant) {
            throw new RuntimeException('Analysis source plan drifted.');
        }
        $assigned = $exposed = $outcomes = array_fill_keys(array_keys($weights), 0);
        $cutoff = $plan->horizonUtc->format('Y-m-d H:i:s');
        $late = (int) DB::table('experiment_assignments')->where('workspace_id', $actor->workspaceId)
            ->where('experiment_id', $experiment->id)->where('assigned_at', '>', $cutoff)->count();
        $late += (int) DB::table('experiment_exposures as x')
            ->join('experiment_assignments as a', function ($join): void {
                $join->on('x.assignment_id', '=', 'a.id')->on('x.workspace_id', '=', 'a.workspace_id');
            })->where('x.workspace_id', $actor->workspaceId)->where('a.experiment_id', $experiment->id)
            ->where('x.exposed_at', '>', $cutoff)->count();
        $late += (int) DB::table('campaign_experiment_outcomes')->where('workspace_id', $actor->workspaceId)
            ->where('binding_id', $binding->id)->where('occurred_at', '>', $cutoff)->count();
        $assignmentRows = DB::table('experiment_assignments')->where('workspace_id', $actor->workspaceId)
            ->where('experiment_id', $experiment->id)->where('assigned_at', '<=', $cutoff)
            ->selectRaw('variant, plan_hash, count(*) as total')
            ->groupBy('variant', 'plan_hash')->get();
        $integrity = 0;
        foreach ($assignmentRows as $item) {
            if (! array_key_exists($item->variant, $assigned) || $item->plan_hash !== $experiment->plan_hash) {
                $integrity += (int) $item->total;
            } else {
                $assigned[$item->variant] += (int) $item->total;
            }
        }
        $exposureRows = DB::table('experiment_exposures as x')
            ->join('experiment_assignments as a', function ($join): void {
                $join->on('x.assignment_id', '=', 'a.id')->on('x.workspace_id', '=', 'a.workspace_id');
            })->where('x.workspace_id', $actor->workspaceId)->where('a.experiment_id', $experiment->id)
            ->where('x.exposed_at', '<=', $cutoff)->where('a.assigned_at', '<=', $cutoff)
            ->selectRaw('a.variant as assigned_variant, x.variant, count(distinct x.assignment_id) as units')
            ->groupBy('a.variant', 'x.variant')->get();
        foreach ($exposureRows as $item) {
            if (! array_key_exists($item->assigned_variant, $exposed) || $item->assigned_variant !== $item->variant) {
                $integrity += (int) $item->units;
            } else {
                $exposed[$item->variant] += (int) $item->units;
            }
        }
        $outcomeRows = DB::table('campaign_experiment_outcomes as o')
            ->join('experiment_assignments as a', function ($join): void {
                $join->on('o.assignment_id', '=', 'a.id')->on('o.workspace_id', '=', 'a.workspace_id');
            })->where('o.workspace_id', $actor->workspaceId)->where('o.binding_id', $binding->id)
            ->where('o.occurred_at', '<=', $cutoff)->where('a.assigned_at', '<=', $cutoff)
            ->selectRaw('a.variant as assigned_variant, o.variant, o.state, count(distinct o.assignment_id) as units')
            ->groupBy('a.variant', 'o.variant', 'o.state')->get();
        $quarantined = 0;
        foreach ($outcomeRows as $item) {
            if ($item->state !== 'admitted') {
                $quarantined += (int) $item->units;
            } elseif ($item->assigned_variant !== $item->variant || ! array_key_exists($item->variant, $outcomes)) {
                $integrity += (int) $item->units;
            } else {
                $outcomes[$item->variant] += (int) $item->units;
            }
        }
        // An observed admitted event is a binary offline contract signal, not a verified conversion.
        $report = ExperimentStatistics::evaluate($plan, $assigned, $exposed, $outcomes, $quarantined, $integrity + $late, $at);

        return ['plan_id' => $planId, 'plan_hash' => $row->plan_hash, 'evidence_kind' => 'offline_admitted_outcome_event',
            'publication_authorized' => false, ...$report];
    }

    private function verify(object $row): ExperimentAnalysisPlan
    {
        $data = json_decode($row->plan, true, 16, JSON_THROW_ON_ERROR);
        $plan = new ExperimentAnalysisPlan($data['binding_id'], $data['unit_kind'], $data['weights'], $data['control'],
            $data['holdout'], $data['alpha'], $data['power'], $data['baseline_rate'], $data['minimum_detectable_difference'],
            new DateTimeImmutable($data['horizon_utc']));
        if ($data !== $plan->canonical() || $row->binding_id !== $plan->bindingId || $row->plan_hash !== $plan->fingerprint()) {
            throw new RuntimeException('Frozen analysis integrity failed.');
        }

        return $plan;
    }

    private function hasAssignments(TenantContext $actor, string $experimentId): bool
    {
        return DB::table('experiment_assignments')->where('workspace_id', $actor->workspaceId)->where('experiment_id', $experimentId)->exists();
    }

    private function scope(TenantContext $actor, string $bindingId): array
    {
        $binding = DB::table('campaign_experiment_bindings')->where('id', $bindingId)->where('workspace_id', $actor->workspaceId)->first()
            ?? throw new InvalidArgumentException('Analysis binding outside workspace.');
        $experiment = DB::table('experiments')->where('id', $binding->experiment_id)->where('workspace_id', $actor->workspaceId)
            ->where('brand_id', $actor->brandId)->first()
            ?? throw new InvalidArgumentException('Analysis experiment outside brand scope.');
        if ($binding->plan_hash !== $experiment->plan_hash) {
            throw new RuntimeException('Binding experiment integrity failed.');
        }

        return [$binding, $experiment];
    }

    private function row(TenantContext $actor, string $id, bool $lock): object
    {
        $query = DB::table('experiment_analysis_plans')->where('id', $id)->where('workspace_id', $actor->workspaceId);

        return ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw new InvalidArgumentException('Analysis outside workspace.');
    }

    private function permit(TenantContext $actor, string $permission): void
    {
        if (! $this->access->allows($actor, $permission)) {
            throw new InvalidArgumentException('Analysis permission denied.');
        }
    }
}
