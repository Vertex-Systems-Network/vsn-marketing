<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Application\BoundedAutonomyOfflineCanaryReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryCohortSource;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Persist strictly offline evidence for a frozen/independently approved plan.
 * This service cannot change cohorts, assign recipients or publish a winner.
 */
final readonly class DatabaseBoundedAutonomyCanaryReviewReceipt
{
    public function __construct(
        private BoundedAutonomyCanaryCohortSource $source,
        private ExperimentAccess $access,
    ) {}

    public function record(TenantContext $actor, ExperimentPlan $plan, DateTimeImmutable $at): array
    {
        if (! $this->access->allows($actor, PermissionCatalog::CAMPAIGN_READ)
            || $actor->actorId === '') {
            throw new InvalidArgumentException('Permissioned experiment operator required.');
        }

        return DB::transaction(function () use ($actor, $plan, $at): array {
            if (! DB::table('workspaces')->where('id', $actor->workspaceId)
                ->where('organization_id', $actor->organizationId)->exists()) {
                throw new InvalidArgumentException('Foreign canary workspace rejected.');
            }
            $row = DB::table('experiments')
                ->where('id', $plan->id)->where('workspace_id', $actor->workspaceId)
                ->where('brand_id', $actor->brandId)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'active'
                || $row->plan_hash !== $plan->fingerprint()
                || $row->approved_by_actor_id === null
                || $row->approved_by_actor_id === $row->created_by_actor_id) {
                return [
                    'status' => 'held_offline',
                    'reason_code' => 'canonical_experiment_not_frozen_or_independently_approved',
                    'execution_authorized' => false,
                    'promotion_authorized' => false,
                    'offline_receipt_recorded' => false,
                ];
            }

            $review = (new BoundedAutonomyOfflineCanaryReview($this->source))->inspect($actor, $plan, $at);
            if ($review['status'] !== 'offline_cohort_review_ready') {
                return $review + ['offline_receipt_recorded' => false];
            }

            $id = (string) Str::uuid();
            DB::table('ai_autonomy_offline_canary_reviews')->insertOrIgnore([
                'id' => $id,
                'workspace_id' => $actor->workspaceId,
                'experiment_id' => $plan->id,
                'brand_id' => $actor->brandId,
                'plan_sha256' => $plan->fingerprint(),
                'assignment_manifest_sha256' => $review['assignment_manifest_sha256'],
                'assigned_denominator' => $review['assigned_denominator'],
                'holdout_denominator' => $review['holdout_denominator'],
                'status' => 'offline_review_only',
                'recorded_by_actor_id' => $actor->actorId,
                'created_at' => now(),
            ]);
            $receipt = DB::table('ai_autonomy_offline_canary_reviews')
                ->where('workspace_id', $actor->workspaceId)->where('experiment_id', $plan->id)->first();

            if ($receipt === null
                || $receipt->plan_sha256 !== $plan->fingerprint()
                || $receipt->assignment_manifest_sha256 !== $review['assignment_manifest_sha256']
                || (int) $receipt->assigned_denominator !== $review['assigned_denominator']
                || (int) $receipt->holdout_denominator !== $review['holdout_denominator']
                || $receipt->brand_id !== $actor->brandId
                || $receipt->status !== 'offline_review_only') {
                throw new InvalidArgumentException('Frozen canary evidence replay conflicts with current source.');
            }

            return $review + [
                'receipt_id' => $receipt->id,
                'offline_receipt_recorded' => true,
                'execution_authorized' => false,
                'promotion_authorized' => false,
            ];
        }, 3);
    }
}
