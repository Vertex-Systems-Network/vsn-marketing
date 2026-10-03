<?php

namespace App\Modules\Analytics\Infrastructure;

use App\Modules\Analytics\Domain\RevenueExperimentVerifier;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentAllocator;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Experiments\Domain\ExposureVerifier;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Explicitly composed only when the experiment key and independent witness are available. */
final readonly class CanonicalRevenueExperimentVerifier implements RevenueExperimentVerifier
{
    public function __construct(private ExperimentAllocator $allocator, private ExperimentAccess $access, private ExposureVerifier $witness) {}

    public function reference(TenantContext $actor, string $contactId, string $exposureId, DateTimeImmutable $conversionAt): ?array
    {
        if (! $this->access->allows($actor, PermissionCatalog::CAMPAIGN_READ)) {
            return null;
        }
        $row = DB::table('experiment_exposures as x')->join('experiment_assignments as a', function ($join): void {
            $join->on('a.id', '=', 'x.assignment_id')->on('a.workspace_id', '=', 'x.workspace_id');
        })->join('experiments as e', function ($join): void {
            $join->on('e.id', '=', 'a.experiment_id')->on('e.workspace_id', '=', 'a.workspace_id');
        })->where('x.id', $exposureId)->where('x.workspace_id', $actor->workspaceId)->where('e.brand_id', $actor->brandId)
            ->select('e.*', 'a.id as assignment_id', 'a.subject_key', 'a.variant as assignment_variant',
                'a.plan_hash as assignment_hash', 'a.assigned_at', 'x.variant as exposure_variant', 'x.exposed_at', 'x.treatment_reference')->first();
        if ($row === null || $row->unit_kind !== 'contact' || $row->status !== 'active'
            || $row->key_fingerprint !== $this->allocator->keyFingerprint()
            || $row->assignment_variant !== $row->exposure_variant || $row->assignment_variant === $row->holdout_variant
            || $row->assignment_hash !== $row->plan_hash) {
            return null;
        }
        $plan = new ExperimentPlan($row->id, $row->workspace_id, $row->brand_id, $row->layer, $row->unit_kind,
            json_decode($row->allocation, true, 16, JSON_THROW_ON_ERROR), $row->control_variant, $row->holdout_variant);
        $at = new DateTimeImmutable($row->exposed_at);
        if ($plan->fingerprint() !== $row->plan_hash || $this->allocator->subjectKey($plan, $contactId) !== $row->subject_key
            || $this->allocator->variant($plan, $contactId) !== $row->assignment_variant
            || new DateTimeImmutable($row->assigned_at) > $at || $at >= $conversionAt
            || ! $this->witness->witnessed($actor, $row->id, $row->assignment_id, $row->assignment_variant, $row->treatment_reference, $at)) {
            return null;
        }

        return ['exposure_id' => $exposureId, 'assignment_id' => $row->assignment_id, 'experiment_id' => $row->id,
            'variant' => $row->assignment_variant, 'plan_hash' => $row->plan_hash, 'exposed_at_utc' => $at->format(DATE_ATOM),
            'evidence' => 'independently_witnessed_canonical_link', 'causal_result' => false];
    }
}
