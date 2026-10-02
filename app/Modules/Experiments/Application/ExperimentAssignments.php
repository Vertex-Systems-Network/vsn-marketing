<?php

namespace App\Modules\Experiments\Application;

use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAllocator;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Experiments\Domain\ExposureVerifier;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final readonly class ExperimentAssignments
{
    public function __construct(private ExperimentAllocator $allocator, private ExperimentAccess $access, private ExposureVerifier $exposures) {}

    public function create(TenantContext $actor, ExperimentPlan $plan): void
    {
        $this->scope($actor, $plan);
        $this->permit($actor, PermissionCatalog::CAMPAIGN_CREATE);
        if ($plan->brandId !== null && ! DB::table('brands')->where('id', $plan->brandId)
            ->where('workspace_id', $plan->workspaceId)->exists()) {
            throw new InvalidArgumentException('Experiment brand is outside workspace.');
        }
        DB::table('experiments')->insert([
            'id' => $plan->id, 'workspace_id' => $plan->workspaceId, 'brand_id' => $plan->brandId,
            'layer' => $plan->layer, 'unit_kind' => $plan->unitKind, 'control_variant' => $plan->control,
            'holdout_variant' => $plan->holdout, 'allocation' => json_encode($plan->canonical()['weights'], JSON_THROW_ON_ERROR),
            'plan_hash' => $plan->fingerprint(), 'key_fingerprint' => $this->allocator->keyFingerprint(),
            'status' => 'draft', 'created_by_actor_id' => $actor->actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function activate(TenantContext $reviewer, string $experimentId): void
    {
        $this->permit($reviewer, PermissionCatalog::CAMPAIGN_APPROVE);
        DB::transaction(function () use ($reviewer, $experimentId): void {
            $row = $this->query($reviewer, $experimentId)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'draft' || $row->created_by_actor_id === $reviewer->actorId
                || $row->key_fingerprint !== $this->allocator->keyFingerprint()) {
                throw new InvalidArgumentException('Independent experiment activation denied.');
            }
            $this->query($reviewer, $experimentId)->update([
                'status' => 'active', 'approved_by_actor_id' => $reviewer->actorId,
                'activated_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** @return array{id:string,variant:string,plan_hash:string,holdout:bool} */
    public function assign(TenantContext $actor, string $experimentId, string $unitId): array
    {
        $this->permit($actor, PermissionCatalog::CAMPAIGN_READ);

        return DB::transaction(function () use ($actor, $experimentId, $unitId): array {
            $row = $this->query($actor, $experimentId)->first();
            if ($row === null || $row->status !== 'active' || $row->key_fingerprint !== $this->allocator->keyFingerprint()) {
                throw new InvalidArgumentException('Experiment not active within scope or allocation key changed.');
            }
            $plan = $this->plan($row);
            if ($plan->fingerprint() !== $row->plan_hash) {
                throw new RuntimeException('Frozen experiment plan integrity failed.');
            }
            $subject = $this->allocator->subjectKey($plan, $unitId);
            $variant = $this->allocator->variant($plan, $unitId);
            $id = (string) Str::uuid();
            DB::table('experiment_assignments')->insertOrIgnore([
                'id' => $id, 'workspace_id' => $actor->workspaceId, 'experiment_id' => $experimentId,
                'layer' => $plan->layer, 'subject_key' => $subject, 'variant' => $variant,
                'plan_hash' => $plan->fingerprint(), 'assigned_at' => now(),
            ]);
            $assignment = DB::table('experiment_assignments')->where('workspace_id', $actor->workspaceId)
                ->where('experiment_id', $experimentId)->where('subject_key', $subject)->first();
            if ($assignment === null || $assignment->variant !== $variant || $assignment->plan_hash !== $plan->fingerprint()) {
                throw new RuntimeException('Mutual exclusion or assignment integrity denied.');
            }

            return ['id' => $assignment->id, 'variant' => $variant,
                'plan_hash' => $plan->fingerprint(), 'holdout' => $variant === $plan->holdout];
        }, 3);
    }

    public function recordExposure(TenantContext $actor, string $experimentId, string $assignmentId, string $variant, string $reference, DateTimeImmutable $at): string
    {
        $this->permit($actor, PermissionCatalog::CAMPAIGN_READ);
        if ($reference === '' || strlen($reference) > 191 || $at->getTimestamp() > time() + 60) {
            throw new InvalidArgumentException('Invalid treatment evidence.');
        }
        $row = $this->query($actor, $experimentId)->first();
        $assignment = DB::table('experiment_assignments')->where('workspace_id', $actor->workspaceId)
            ->where('experiment_id', $experimentId)->where('id', $assignmentId)->first();
        if ($row === null || $row->status !== 'active' || $assignment === null || $assignment->variant !== $variant
            || $row->key_fingerprint !== $this->allocator->keyFingerprint()
            || $assignment->plan_hash !== $row->plan_hash || $variant === $row->holdout_variant
            || $at < new DateTimeImmutable($assignment->assigned_at, new DateTimeZone('UTC'))
            || ! $this->exposures->witnessed($actor, $experimentId, $assignmentId, $variant, $reference, $at)) {
            throw new InvalidArgumentException('Exposure lacks a trusted matching treatment witness.');
        }
        $id = (string) Str::uuid();
        DB::table('experiment_exposures')->insertOrIgnore([
            'id' => $id, 'workspace_id' => $actor->workspaceId, 'assignment_id' => $assignmentId,
            'treatment_reference' => $reference, 'variant' => $variant, 'exposed_at' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
        $record = DB::table('experiment_exposures')->where('workspace_id', $actor->workspaceId)
            ->where('assignment_id', $assignmentId)->where('treatment_reference', $reference)->first();
        if ($record === null || $record->variant !== $variant) {
            throw new RuntimeException('Exposure idempotency conflict.');
        }

        return $record->id;
    }

    private function permit(TenantContext $actor, string $permission): void
    {
        if (! $this->access->allows($actor, $permission)) {
            throw new InvalidArgumentException('Experiment permission denied.');
        }
    }

    private function scope(TenantContext $actor, ExperimentPlan $plan): void
    {
        if ($actor->workspaceId !== $plan->workspaceId || $actor->brandId !== $plan->brandId) {
            throw new InvalidArgumentException('Experiment scope denied.');
        }
    }

    private function query(TenantContext $actor, string $id): Builder
    {
        return DB::table('experiments')->where('id', $id)->where('workspace_id', $actor->workspaceId)
            ->where('brand_id', $actor->brandId);
    }

    private function plan(object $row): ExperimentPlan
    {
        return new ExperimentPlan($row->id, $row->workspace_id, $row->brand_id, $row->layer, $row->unit_kind,
            json_decode($row->allocation, true, 16, JSON_THROW_ON_ERROR), $row->control_variant, $row->holdout_variant);
    }
}
