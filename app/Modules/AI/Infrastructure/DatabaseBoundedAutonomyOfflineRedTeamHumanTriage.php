<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Identity\Application\Authorization\WorkspaceAuthorizer;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Identity\User;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Human-session-only note on an already recorded synthetic red-team review.
 *
 * No recovery/incident-closure/provider action is implemented. Only a minimal,
 * scoped operator acknowledgement or escalation is append-only audited.
 */
final readonly class DatabaseBoundedAutonomyOfflineRedTeamHumanTriage
{
    private const string SOURCE_ACTION = 'ai.autonomy.redteam.offline.review';

    private const string TRIAGE_ACTION = 'ai.autonomy.redteam.offline.triage';

    private const array DECISIONS = ['investigation_requested', 'acknowledged', 'escalated'];

    public function __construct(
        private WorkspaceAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {}

    public function record(
        User $human,
        TenantContext $requester,
        string $runId,
        string $sourceEventId,
        string $decision,
    ): array {
        $humanId = (string) $human->getKey();
        if ((string) Auth::id() !== $humanId
            || $humanId === $requester->actorId
            || ! in_array($decision, self::DECISIONS, true)
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', $sourceEventId) !== 1) {
            throw new InvalidArgumentException('Independent authenticated offline triage authority required.');
        }

        return DB::transaction(function () use ($human, $humanId, $requester, $runId, $sourceEventId, $decision): array {
            // Serialize only this tenant's audit decisions and reject forged
            // organization ownership rather than trusting a supplied context.
            if (DB::table('workspaces')->where('id', $requester->workspaceId)
                ->where('organization_id', $requester->organizationId)
                ->lockForUpdate()->first() === null) {
                throw new InvalidArgumentException('Foreign offline incident workspace rejected.');
            }
            $reviewerContext = new TenantContext(
                $requester->organizationId, $requester->workspaceId,
                $requester->brandId, $humanId,
            );
            if (! $this->authorizer->allows($human, $reviewerContext, PermissionCatalog::AI_APPROVE)) {
                throw new InvalidArgumentException('Current independent incident-triage authority missing.');
            }

            $source = DB::table('audit_events')
                ->where('id', $sourceEventId)
                ->where('workspace_id', $requester->workspaceId)
                ->where('action', self::SOURCE_ACTION)
                ->where('subject_type', 'offline_redteam_run')
                ->where('subject_id', $runId)
                ->first();
            if ($source === null || $source->actor_id !== $requester->actorId
                || $source->brand_id !== $requester->brandId) {
                throw new InvalidArgumentException('Untrusted or cross-actor incident source.');
            }

            $sourceFacts = json_decode($source->evidence, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($sourceFacts)
                || ($sourceFacts['run_id'] ?? null) !== $runId
                || ! is_int($sourceFacts['sequence'] ?? null)
                || $sourceFacts['sequence'] < 1
                || ! is_string($sourceFacts['review_fingerprint'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $sourceFacts['review_fingerprint']) !== 1
                || ! in_array($sourceFacts['recorded_status'] ?? null, [
                    'held_offline', 'offline_redteam_review_ready',
                ], true)
                || ($sourceFacts['synthetic_evidence_only'] ?? null) !== true
                || ($sourceFacts['production_incident_verified'] ?? null) !== false
                || ($sourceFacts['recovery_performed'] ?? null) !== false
                || ! is_int($sourceFacts['case_count'] ?? null)
                || $sourceFacts['case_count'] < 0 || $sourceFacts['case_count'] > 128) {
                throw new InvalidArgumentException('Untrusted synthetic offline incident source facts.');
            }

            $history = DB::table('audit_events')
                ->where('workspace_id', $requester->workspaceId)
                ->where('action', self::TRIAGE_ACTION)
                ->where('subject_type', 'offline_redteam_run')
                ->where('subject_id', $runId)
                ->get();
            if ($history->count() > 1024) {
                throw new InvalidArgumentException('Offline incident triage audit history bound exceeded.');
            }
            foreach ($history as $event) {
                $fact = json_decode($event->evidence, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($fact)
                    || ($fact['source_event_id'] ?? null) !== $sourceEventId
                    || ($fact['triage_decision'] ?? null) !== $decision
                    || $event->actor_id !== $humanId) {
                    continue;
                }

                return self::result($runId, $event->id, $decision, true);
            }

            $event = $this->audit->record(
                workspaceId: $requester->workspaceId,
                action: self::TRIAGE_ACTION,
                evidence: [
                    'run_id' => $runId,
                    'source_event_id' => $sourceEventId,
                    'source_review_fingerprint' => $sourceFacts['review_fingerprint'],
                    'source_recorded_status' => $sourceFacts['recorded_status'],
                    'source_case_count' => $sourceFacts['case_count'],
                    'triage_decision' => $decision,
                    'synthetic_evidence_only' => true,
                    'production_incident_verified' => false,
                    'incident_resolved' => false,
                    'recovery_performed' => false,
                ],
                brandId: $requester->brandId,
                actorId: $humanId,
                subjectType: 'offline_redteam_run',
                subjectId: $runId,
                correlationId: $runId,
            );

            return self::result($runId, $event->id, $decision, false);
        }, 3);
    }

    private static function result(string $runId, string $eventId, string $decision, bool $replayed): array
    {
        return [
            'status' => 'offline_human_triage_note_recorded',
            'run_id' => $runId,
            'audit_event_id' => $eventId,
            'triage_decision' => $decision,
            'replayed' => $replayed,
            'human_triage_audit_recorded' => true,
            'synthetic_evidence_only' => true,
            'production_incident_verified' => false,
            'incident_resolved' => false,
            'recovery_performed' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
