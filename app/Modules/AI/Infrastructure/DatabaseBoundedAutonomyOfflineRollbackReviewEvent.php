<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Application\BoundedAutonomyOfflineRollbackReview;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Immutable, per-workspace sequence of OFFLINE provider-outcome review facts.
 *
 * The ledger never performs rollback, refund, retry, provider calls or
 * promotion. A later apparently positive callback cannot erase an earlier
 * unknown, late, duplicate or irreversible outcome.
 */
final readonly class DatabaseBoundedAutonomyOfflineRollbackReviewEvent
{
    public function __construct(
        private BoundedAutonomyOfflineRollbackReview $reviewer,
        private ExperimentAccess $access,
    ) {}

    public function record(
        TenantContext $actor,
        string $runId,
        string $snapshotSha256,
        DateTimeImmutable $at,
    ): array {
        if (! $this->access->allows($actor, PermissionCatalog::CAMPAIGN_READ)
            || strlen($actor->actorId) > 64
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotSha256) !== 1) {
            throw new InvalidArgumentException('Unauthorized offline rollback evidence request.');
        }

        return DB::transaction(function () use ($actor, $runId, $snapshotSha256, $at): array {
            // Serialize all reviews for a run through its canonical workspace.
            $scope = DB::table('workspaces')->where('id', $actor->workspaceId)
                ->where('organization_id', $actor->organizationId)
                ->lockForUpdate()->first();
            if ($scope === null) {
                throw new InvalidArgumentException('Foreign rollback workspace rejected.');
            }

            $review = $this->reviewer->inspect($actor, $runId, $snapshotSha256, $at);
            if (($review['run_id'] ?? null) !== $runId
                || ($review['snapshot_sha256'] ?? null) !== $snapshotSha256
                || ! in_array($review['status'] ?? null, ['held_offline', 'offline_rollback_review_ready'], true)
                || ! is_string($review['reason_code'] ?? null)
                || ($review['rollback_performed'] ?? null) !== false
                || ($review['refund_authorized'] ?? null) !== false
                || ($review['retry_authorized'] ?? null) !== false
                || ($review['execution_authorized'] ?? null) !== false
                || ($review['promotion_authorized'] ?? null) !== false
                || ! is_bool($review['external_outcome_verified'] ?? null)) {
                throw new InvalidArgumentException('Rollback reviewer supplied unauthorized or malformed outcome.');
            }

            // Hash exact independently reviewed decision rather than a model
            // narrative. The digest identifies an idempotent review event.
            $digest = hash('sha256', json_encode([
                'tenant' => $actor->toArray(),
                'run_id' => $runId,
                'snapshot_sha256' => $snapshotSha256,
                'review' => $review,
            ], JSON_THROW_ON_ERROR));

            $existing = DB::table('ai_autonomy_offline_rollback_events')
                ->where('workspace_id', $actor->workspaceId)
                ->where('run_id', $runId)
                ->where('review_fingerprint', $digest)->first();
            if ($existing !== null) {
                if ($existing->actor_id !== $actor->actorId
                    || $existing->brand_id !== $actor->brandId
                    || $existing->snapshot_sha256 !== $snapshotSha256) {
                    throw new InvalidArgumentException('Cross-actor or changed-plan rollback replay rejected.');
                }

                return $this->result($existing, true);
            }

            $previous = DB::table('ai_autonomy_offline_rollback_events')
                ->where('workspace_id', $actor->workspaceId)
                ->where('run_id', $runId)
                ->orderByDesc('sequence')->first();
            if ($previous !== null && ($previous->snapshot_sha256 !== $snapshotSha256
                || $previous->brand_id !== $actor->brandId
                || $previous->actor_id !== $actor->actorId)) {
                throw new InvalidArgumentException('Rollback review cannot silently change run identity.');
            }

            $status = $review['status'];
            $reason = $review['reason_code'];
            $verified = $review['external_outcome_verified'];
            if ($previous !== null && $status === 'offline_rollback_review_ready'
                && $previous->status === 'held_offline') {
                // An earlier provider uncertainty stays unresolved until an
                // independently approved reconciliation flow is installed.
                $status = 'held_offline';
                $reason = 'prior_uncertain_outcome_requires_human_reconciliation';
                $verified = false;
            }

            $id = (string) Str::uuid();
            $sequence = $previous === null ? 1 : (int) $previous->sequence + 1;
            DB::table('ai_autonomy_offline_rollback_events')->insert([
                'id' => $id,
                'workspace_id' => $actor->workspaceId,
                'brand_id' => $actor->brandId,
                'actor_id' => $actor->actorId,
                'run_id' => $runId,
                'snapshot_sha256' => $snapshotSha256,
                'review_fingerprint' => $digest,
                'sequence' => $sequence,
                'reviewed_at_unix' => $at->getTimestamp(),
                'status' => $status,
                'reason_code' => $reason,
                'external_outcome_verified' => $verified,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $id,
                'sequence' => $sequence,
                'status' => $status,
                'reason_code' => $reason,
                'replayed' => false,
                'rollback_performed' => false,
                'refund_authorized' => false,
                'retry_authorized' => false,
                'execution_authorized' => false,
                'promotion_authorized' => false,
            ];
        }, 3);
    }

    private function result(object $event, bool $replayed): array
    {
        return [
            'id' => $event->id,
            'sequence' => (int) $event->sequence,
            'status' => $event->status,
            'reason_code' => $event->reason_code,
            'replayed' => $replayed,
            'rollback_performed' => false,
            'refund_authorized' => false,
            'retry_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
