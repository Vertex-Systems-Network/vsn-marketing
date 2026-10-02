<?php

namespace App\Modules\Experiments\Application;

use App\Modules\Experiments\Domain\CampaignExperimentMatrix;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentEligibility;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Offline candidate and outcome admission. It cannot render, send or witness an exposure. */
final readonly class CampaignExperiments
{
    public function __construct(private ExperimentAssignments $assignments, private ExperimentAccess $access, private ExperimentEligibility $eligibility) {}

    public function bind(TenantContext $actor, string $experimentId, string $snapshotId, CampaignExperimentMatrix $matrix): string
    {
        $this->permit($actor, PermissionCatalog::CAMPAIGN_CREATE);

        return DB::transaction(function () use ($actor, $experimentId, $snapshotId, $matrix): string {
            $experiment = $this->experiment($actor, $experimentId, true);
            $snapshot = DB::table('campaign_snapshots')->where('id', $snapshotId)->where('workspace_id', $actor->workspaceId)->first();
            $weights = json_decode($experiment->allocation, true, 16, JSON_THROW_ON_ERROR);
            $arms = array_keys($weights);
            if ($experiment->status !== 'draft' || $snapshot === null || $experiment->created_by_actor_id !== $actor->actorId
                || ($snapshot->brand_reference !== null && ! $this->brandMatches($snapshot->brand_reference, $experiment->brand_id))
                || $snapshot->content_version_id !== $matrix->variants[$experiment->control_variant]['content']
                || $experiment->plan_hash !== $this->planHash($experiment)
                || array_diff(array_keys($matrix->variants), array_diff($arms, array_filter([$experiment->holdout_variant])))
                || array_diff(array_diff($arms, array_filter([$experiment->holdout_variant])), array_keys($matrix->variants))) {
                throw new InvalidArgumentException('Campaign matrix does not match frozen plan and canonical snapshot.');
            }
            foreach ($matrix->variants as $candidate) {
                if (! DB::table('content_versions')->where('id', $candidate['content'])->where('workspace_id', $actor->workspaceId)->exists()
                    || ! DB::table('segment_definition_versions')->where('id', $candidate['audience'])->where('workspace_id', $actor->workspaceId)->exists()) {
                    throw new InvalidArgumentException('Candidate reference is outside canonical workspace.');
                }
            }
            $id = (string) Str::uuid();
            DB::table('campaign_experiment_bindings')->insert([
                'id' => $id, 'workspace_id' => $actor->workspaceId, 'experiment_id' => $experimentId,
                'snapshot_id' => $snapshotId, 'snapshot_hash' => $snapshot->snapshot_hash,
                'plan_hash' => $experiment->plan_hash, 'matrix_hash' => $matrix->fingerprint(),
                'variants' => json_encode($matrix->variants, JSON_THROW_ON_ERROR), 'status' => 'ready',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        }, 3);
    }

    /** @return array{assignment_id:string,variant:string,content:string,time:string,audience:string,reference:string}|array{assignment_id:string,variant:string,holdout:true} */
    public function candidate(TenantContext $actor, string $bindingId, string $unitId): array
    {
        $this->permit($actor, PermissionCatalog::CAMPAIGN_READ);
        $binding = $this->binding($actor, $bindingId);
        $experiment = $this->experiment($actor, $binding->experiment_id);
        $matrix = $this->verify($actor, $binding, $experiment);
        if ($binding->status !== 'ready' || $experiment->status !== 'active'
            || ! $this->eligibility->allows($actor, $experiment->unit_kind, $unitId)) {
            throw new InvalidArgumentException('Candidate denied by status, identity, consent or suppression.');
        }
        $assignment = $this->assignments->assign($actor, $binding->experiment_id, $unitId);
        if ($assignment['holdout']) {
            return ['assignment_id' => $assignment['id'], 'variant' => $assignment['variant'], 'holdout' => true];
        }
        $arm = $matrix->variants[$assignment['variant']] ?? null;
        if ($arm === null) {
            throw new RuntimeException('Assignment has no frozen candidate.');
        }

        return ['assignment_id' => $assignment['id'], 'variant' => $assignment['variant'], ...$arm,
            'reference' => $this->reference($bindingId, $assignment['id'], $assignment['variant'])];
    }

    public function admitOutcome(TenantContext $actor, string $bindingId, string $assignmentId, string $variant, string $reference, string $eventReference, DateTimeImmutable $at): string
    {
        $this->permit($actor, PermissionCatalog::CAMPAIGN_READ);
        if ($eventReference === '' || strlen($eventReference) > 191 || $reference === '' || strlen($reference) > 191
            || $at->getTimestamp() > time() + 60) {
            throw new InvalidArgumentException('Invalid outcome provenance.');
        }

        return DB::transaction(function () use ($actor, $bindingId, $assignmentId, $variant, $reference, $eventReference, $at): string {
            $existing = DB::table('campaign_experiment_outcomes')->where('workspace_id', $actor->workspaceId)->where('event_reference', $eventReference)->first();
            if ($existing !== null) {
                if ($existing->binding_id !== $bindingId || $existing->assignment_id !== $assignmentId
                    || $existing->variant !== $variant || $existing->treatment_reference !== $reference
                    || $existing->occurred_at !== $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')) {
                    throw new InvalidArgumentException('Outcome event replay conflict.');
                }

                return $existing->state;
            }
            $binding = $this->binding($actor, $bindingId);
            $experiment = $this->experiment($actor, $binding->experiment_id);
            $matrix = $this->verify($actor, $binding, $experiment);
            $assignment = DB::table('experiment_assignments')->where('id', $assignmentId)
                ->where('workspace_id', $actor->workspaceId)->where('experiment_id', $binding->experiment_id)->first();
            if ($assignment === null) {
                throw new InvalidArgumentException('Outcome assignment outside bound experiment.');
            }
            $reason = null;
            $exposure = DB::table('experiment_exposures')->where('workspace_id', $actor->workspaceId)
                ->where('assignment_id', $assignmentId)->where('treatment_reference', $reference)->first();
            $crossed = DB::table('experiment_exposures')->where('workspace_id', $actor->workspaceId)
                ->where('assignment_id', $assignmentId)->where('variant', '!=', $assignment->variant)->exists();
            if ($assignment->variant !== $variant || ! isset($matrix->variants[$variant]) || $crossed
                || $reference !== $this->reference($bindingId, $assignmentId, $variant)
                || ($exposure !== null && $exposure->variant !== $variant)) {
                $reason = 'contaminated_treatment';
            } elseif ($exposure === null || new DateTimeImmutable($exposure->exposed_at, new DateTimeZone('UTC')) > $at) {
                $reason = 'missing_prior_exposure';
            } elseif ($binding->status !== 'ready' || $experiment->status !== 'active') {
                $reason = 'rolled_back';
            }
            DB::table('campaign_experiment_outcomes')->insert([
                'id' => (string) Str::uuid(), 'workspace_id' => $actor->workspaceId, 'binding_id' => $bindingId,
                'assignment_id' => $assignmentId, 'event_reference' => $eventReference,
                'treatment_reference' => $reference, 'variant' => $variant,
                'state' => $reason === null ? 'admitted' : 'quarantined', 'reason' => $reason,
                'occurred_at' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'created_at' => now(),
            ]);

            return $reason === null ? 'admitted' : 'quarantined';
        }, 3);
    }

    public function rollback(TenantContext $actor, string $bindingId): void
    {
        $this->permit($actor, PermissionCatalog::CAMPAIGN_APPROVE);
        DB::table('campaign_experiment_bindings')->where('id', $bindingId)
            ->where('workspace_id', $actor->workspaceId)->update(['status' => 'stopped', 'updated_at' => now()]);
    }

    private function reference(string $binding, string $assignment, string $variant): string
    {
        return 'campaign:'.$binding.':'.$assignment.':'.$variant;
    }

    private function verify(TenantContext $actor, object $binding, object $experiment): CampaignExperimentMatrix
    {
        $snapshot = DB::table('campaign_snapshots')->where('id', $binding->snapshot_id)->where('workspace_id', $actor->workspaceId)->first();
        $matrix = new CampaignExperimentMatrix(json_decode($binding->variants, true, 16, JSON_THROW_ON_ERROR));
        if ($snapshot === null || $snapshot->snapshot_hash !== $binding->snapshot_hash
            || $experiment->plan_hash !== $binding->plan_hash || $matrix->fingerprint() !== $binding->matrix_hash) {
            throw new RuntimeException('Frozen campaign binding integrity failed.');
        }

        return $matrix;
    }

    private function binding(TenantContext $actor, string $id): object
    {
        return DB::table('campaign_experiment_bindings')->where('id', $id)->where('workspace_id', $actor->workspaceId)->first()
            ?? throw new InvalidArgumentException('Campaign binding outside workspace.');
    }

    private function experiment(TenantContext $actor, string $id, bool $lock = false): object
    {
        $query = DB::table('experiments')->where('id', $id)->where('workspace_id', $actor->workspaceId)->where('brand_id', $actor->brandId);

        return ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw new InvalidArgumentException('Experiment outside actor scope.');
    }

    private function planHash(object $row): string
    {
        return (new ExperimentPlan($row->id, $row->workspace_id, $row->brand_id,
            $row->layer, $row->unit_kind, json_decode($row->allocation, true, 16, JSON_THROW_ON_ERROR),
            $row->control_variant, $row->holdout_variant))->fingerprint();
    }

    private function brandMatches(string $reference, ?string $brand): bool
    {
        $decoded = json_decode($reference, true, 16, JSON_THROW_ON_ERROR);

        return ($decoded['id'] ?? null) === $brand;
    }

    private function permit(TenantContext $actor, string $permission): void
    {
        if (! $this->access->allows($actor, $permission)) {
            throw new InvalidArgumentException('Campaign experiment permission denied.');
        }
    }
}
