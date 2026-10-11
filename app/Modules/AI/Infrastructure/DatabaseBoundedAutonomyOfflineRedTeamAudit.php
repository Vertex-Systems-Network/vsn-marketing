<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Application\BoundedAutonomyOfflineRedTeamIncidentReview;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Audits only scoped synthetic OFFLINE red-team review facts.
 *
 * This is NOT a real incident declaration or recovery workflow. The existing
 * canonical audit_events ledger is used without any new database migration.
 * A workspace row lock serializes fingerprint replay checks with appends.
 */
final readonly class DatabaseBoundedAutonomyOfflineRedTeamAudit
{
    private const string ACTION = 'ai.autonomy.redteam.offline.review';

    private const array CATEGORIES = [
        'goal_hijack', 'tool_misuse', 'self_approval', 'cross_tenant',
        'quota_race', 'emergency_stop', 'provider_replay',
        'metric_spoofing', 'exfiltration',
    ];

    private const array SEVERITIES = ['low', 'medium', 'high', 'critical'];

    private const array REVIEW_FIELDS = [
        'status', 'reason_code', 'run_id', 'source_manifest_sha256',
        'case_count', 'severity_counts', 'category_counts',
        'synthetic_evidence_only', 'production_incident_verified',
        'incident_recorded', 'recovery_performed', 'execution_authorized',
        'promotion_authorized',
    ];

    public function __construct(
        private BoundedAutonomyOfflineRedTeamIncidentReview $reviewer,
        private ExperimentAccess $access,
        private AuditRecorder $audit,
    ) {}

    public function record(TenantContext $actor, string $runId, DateTimeImmutable $at): array
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId) !== 1
            || $actor->actorId === '' || strlen($actor->actorId) > 64
            || ! $this->access->allows($actor, PermissionCatalog::CAMPAIGN_READ)) {
            throw new InvalidArgumentException('Authorized offline red-team audit scope required.');
        }

        return DB::transaction(function () use ($actor, $runId, $at): array {
            // A supplied TenantContext must not invent a workspace owner. One
            // row lock serializes the immutable audit stream for this tenant.
            if (DB::table('workspaces')->where('id', $actor->workspaceId)
                ->where('organization_id', $actor->organizationId)
                ->lockForUpdate()->first() === null) {
                throw new InvalidArgumentException('Foreign red-team audit workspace rejected.');
            }

            $review = $this->reviewer->inspect($actor, $runId, $at);
            self::assertReview($review, $runId);

            $fingerprint = hash('sha256', json_encode([
                'tenant' => $actor->toArray(),
                'run_id' => $runId,
                'review' => $review,
            ], JSON_THROW_ON_ERROR));
            $previous = DB::table('audit_events')
                ->where('workspace_id', $actor->workspaceId)
                ->where('action', self::ACTION)
                ->where('subject_type', 'offline_redteam_run')
                ->where('subject_id', $runId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $priorEvidence = null;
            if ($previous !== null) {
                if ($previous->actor_id !== $actor->actorId || $previous->brand_id !== $actor->brandId) {
                    throw new InvalidArgumentException('Changed-actor red-team audit replay rejected.');
                }
                $priorEvidence = json_decode($previous->evidence, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($priorEvidence)
                    || ! is_string($priorEvidence['review_fingerprint'] ?? null)
                    || ! in_array($priorEvidence['recorded_status'] ?? null, ['held_offline', 'offline_redteam_review_ready'], true)) {
                    throw new InvalidArgumentException('Prior red-team audit record is corrupt.');
                }
                if (hash_equals($priorEvidence['review_fingerprint'], $fingerprint)) {
                    return self::result(
                        $runId, $previous->id, $priorEvidence['recorded_status'],
                        $priorEvidence['recorded_reason'], true,
                    );
                }
            }

            $status = $review['status'];
            $reason = $review['reason_code'];
            if ($priorEvidence !== null && $priorEvidence['recorded_status'] === 'held_offline'
                && $status === 'offline_redteam_review_ready') {
                // A later synthetic pass must not erase a historical safety
                // hold. Explicit authenticated human reconciliation is a
                // separate future workflow, never inferred by this recorder.
                $status = 'held_offline';
                $reason = 'prior_unresolved_redteam_safety_hold';
            }

            $event = $this->audit->record(
                workspaceId: $actor->workspaceId,
                action: self::ACTION,
                evidence: [
                    'run_id' => $runId,
                    'review_fingerprint' => $fingerprint,
                    'source_manifest_sha256' => $review['source_manifest_sha256'],
                    'recorded_status' => $status,
                    'recorded_reason' => $reason,
                    'case_count' => $review['case_count'],
                    'severity_counts' => $review['severity_counts'],
                    'category_counts' => $review['category_counts'],
                    'synthetic_evidence_only' => true,
                    'production_incident_verified' => false,
                    'recovery_performed' => false,
                ],
                brandId: $actor->brandId,
                actorId: $actor->actorId,
                subjectType: 'offline_redteam_run',
                subjectId: $runId,
                correlationId: $runId,
            );

            return self::result($runId, $event->id, $status, $reason, false);
        }, 3);
    }

    private static function assertReview(array $review, string $runId): void
    {
        if (count($review) !== count(self::REVIEW_FIELDS)
            || array_diff(array_keys($review), self::REVIEW_FIELDS) !== []
            || array_diff(self::REVIEW_FIELDS, array_keys($review)) !== []
            || ($review['run_id'] ?? null) !== $runId
            || ($review['synthetic_evidence_only'] ?? null) !== true
            || ($review['production_incident_verified'] ?? null) !== false
            || ($review['incident_recorded'] ?? null) !== false
            || ($review['recovery_performed'] ?? null) !== false
            || ($review['execution_authorized'] ?? null) !== false
            || ($review['promotion_authorized'] ?? null) !== false
            || ! is_int($review['case_count'] ?? null)
            || $review['case_count'] < 0 || $review['case_count'] > 128
            || ! self::counts($review['category_counts'] ?? null, self::CATEGORIES)
            || ! self::counts($review['severity_counts'] ?? null, self::SEVERITIES)) {
            throw new InvalidArgumentException('Untrusted offline red-team audit review.');
        }

        $ready = $review['status'] === 'offline_redteam_review_ready'
            && $review['reason_code'] === 'human_incident_triage_required';
        $held = $review['status'] === 'held_offline'
            && in_array($review['reason_code'], [
                'redteam_evidence_unavailable', 'redteam_coverage_incomplete',
                'unresolved_redteam_safety_case',
            ], true);
        if ((! $ready && ! $held)
            || ($ready && (! is_string($review['source_manifest_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $review['source_manifest_sha256']) !== 1
                || $review['case_count'] === 0
                || array_sum($review['category_counts']) !== $review['case_count']
                || array_sum($review['severity_counts']) !== $review['case_count']))
            || ($held && ($review['source_manifest_sha256'] !== null
                || $review['case_count'] !== 0
                || array_sum($review['category_counts']) !== 0
                || array_sum($review['severity_counts']) !== 0))) {
            throw new InvalidArgumentException('Offline red-team incident cannot assert unsupported evidence.');
        }
    }

    private static function counts(mixed $counts, array $keys): bool
    {
        if (! is_array($counts) || count($counts) !== count($keys)
            || array_diff(array_keys($counts), $keys) !== []
            || array_diff($keys, array_keys($counts)) !== []) {
            return false;
        }
        foreach ($counts as $count) {
            if (! is_int($count) || $count < 0 || $count > 128) {
                return false;
            }
        }

        return true;
    }

    private static function result(
        string $runId,
        string $eventId,
        string $status,
        string $reason,
        bool $replayed,
    ): array {
        return [
            'status' => $status,
            'reason_code' => $reason,
            'run_id' => $runId,
            'audit_event_id' => $eventId,
            'replayed' => $replayed,
            'offline_audit_recorded' => true,
            'synthetic_evidence_only' => true,
            'production_incident_verified' => false,
            'incident_resolved' => false,
            'recovery_performed' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
