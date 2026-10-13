<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Records human review of a synthetic incident without clearing its safety hold. */
final readonly class DatabaseOfflineRedTeamTriage
{
    private const string ACTION = 'ai.autonomy.redteam.offline.triage';

    public function __construct(
        private DatabaseBoundedAutonomyOfflineRedTeamMonitor $monitor,
        private ExperimentAccess $access,
        private AuditRecorder $audit,
    ) {}

    public function record(TenantContext $operator, string $runId, string $auditEventId, string $decision): array
    {
        if (! in_array($decision, ['investigating', 'escalated'], true)
            || $operator->actorId === '' || strlen($operator->actorId) > 64
            || ! $this->access->allows($operator, PermissionCatalog::CAMPAIGN_APPROVE)) {
            throw new InvalidArgumentException('Independent operator triage permission required.');
        }

        return DB::transaction(function () use ($operator, $runId, $auditEventId, $decision): array {
            if (DB::table('workspaces')->where('id', $operator->workspaceId)
                ->where('organization_id', $operator->organizationId)
                ->lockForUpdate()->first() === null) {
                throw new InvalidArgumentException('Foreign incident workspace denied.');
            }

            $review = $this->monitor->inspect($operator, $runId);
            if ($review['latest_audit_event_id'] === null
                || $review['latest_audit_event_id'] !== $auditEventId) {
                throw new InvalidArgumentException('Stale synthetic incident review denied.');
            }

            $source = DB::table('audit_events')->where('id', $auditEventId)
                ->where('workspace_id', $operator->workspaceId)
                ->where('brand_id', $operator->brandId)
                ->where('subject_id', $runId)->first();
            if ($source === null || $source->action !== 'ai.autonomy.redteam.offline.review'
                || $source->actor_id === $operator->actorId) {
                throw new InvalidArgumentException('Independent human triage required.');
            }

            $ordered = $this->history($operator, $runId, true);
            $latest = count($ordered) === 0 ? null : $ordered[count($ordered)];
            $previous = $latest['fact']['fingerprint'] ?? null;
            if ($latest !== null && $latest['fact']['audit_event_id'] === $auditEventId) {
                if ($latest['fact']['decision'] === $decision
                    && $latest['row']->actor_id === $operator->actorId) {
                    return self::result($runId, $latest['row']->id, $auditEventId, $decision, true);
                }
            }
            if ($latest !== null && $latest['fact']['decision'] === 'escalated'
                && $decision === 'investigating') {
                throw new InvalidArgumentException('Synthetic incident escalation cannot be downgraded.');
            }

            $sequence = count($ordered) + 1;
            $fingerprint = self::fingerprint(
                $runId, $auditEventId, $decision, $operator->actorId, $sequence, $previous,
            );

            $event = $this->audit->record(
                workspaceId: $operator->workspaceId,
                action: self::ACTION,
                evidence: [
                    'run_id' => $runId, 'audit_event_id' => $auditEventId,
                    'decision' => $decision, 'synthetic_evidence_only' => true,
                    'incident_resolved' => false, 'recovery_performed' => false,
                    'recovery_review_status' => 'blocked_unverified_external_effect',
                    'sequence' => $sequence, 'previous_fingerprint' => $previous,
                    'fingerprint' => $fingerprint,
                ],
                brandId: $operator->brandId,
                actorId: $operator->actorId,
                subjectType: 'offline_redteam_run',
                subjectId: $runId,
                correlationId: $runId,
            );

            return self::result($runId, $event->id, $auditEventId, $decision, false);
        }, 3);
    }

    public function inspect(TenantContext $operator, string $runId): array
    {
        $review = $this->monitor->inspect($operator, $runId);
        $ordered = $this->history($operator, $runId, false);
        $latest = count($ordered) === 0 ? null : $ordered[count($ordered)];

        return [
            'status' => $latest === null ? 'not_recorded' : $latest['fact']['decision'],
            'triage_sequence' => count($ordered),
            'current_review_triaged' => $latest !== null
                && $latest['fact']['audit_event_id'] === $review['latest_audit_event_id'],
            'recovery_review_status' => 'blocked_unverified_external_effect',
            'recovery_performed' => false,
        ];
    }

    private function history(TenantContext $operator, string $runId, bool $appending): array
    {
        $rows = DB::table('audit_events')->where('workspace_id', $operator->workspaceId)
            ->where('action', self::ACTION)
            ->where('subject_type', 'offline_redteam_run')->where('subject_id', $runId)
            ->limit(1025)->get();
        if ($rows->count() > 1024 || ($appending && $rows->count() === 1024)) {
            throw new InvalidArgumentException('Synthetic triage history limit reached.');
        }
        $ordered = [];
        foreach ($rows as $entry) {
            $fact = json_decode($entry->evidence, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($fact) || count($fact) !== 10
                || array_diff(array_keys($fact), [
                    'run_id', 'audit_event_id', 'decision', 'synthetic_evidence_only',
                    'incident_resolved', 'recovery_performed', 'recovery_review_status', 'sequence',
                    'previous_fingerprint', 'fingerprint',
                ]) !== []
                || ($fact['run_id'] ?? null) !== $runId
                || ! in_array($fact['decision'] ?? null, ['investigating', 'escalated'], true)
                || ($fact['synthetic_evidence_only'] ?? null) !== true
                || ($fact['incident_resolved'] ?? null) !== false
                || ($fact['recovery_performed'] ?? null) !== false
                || ($fact['recovery_review_status'] ?? null) !== 'blocked_unverified_external_effect'
                || ! is_string($fact['audit_event_id'] ?? null)
                || ! is_int($fact['sequence'] ?? null)
                || $fact['sequence'] < 1 || $fact['sequence'] > 1024
                || isset($ordered[$fact['sequence']])
                || ! is_string($entry->actor_id) || $entry->actor_id === ''
                || $entry->brand_id !== $operator->brandId
                || $entry->correlation_id !== $runId
                || ! is_string($fact['fingerprint'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $fact['fingerprint']) !== 1) {
                throw new InvalidArgumentException('Untrusted synthetic triage history.');
            }
            $ordered[$fact['sequence']] = ['row' => $entry, 'fact' => $fact];
        }

        $previous = null;
        $sources = DB::table('audit_events')
            ->whereIn('id', array_values(array_unique(array_map(
                static fn (array $entry): string => $entry['fact']['audit_event_id'], $ordered,
            ))))
            ->get()->keyBy('id');
        for ($sequence = 1; $sequence <= count($ordered); $sequence++) {
            $entry = $ordered[$sequence] ?? null;
            $source = $entry === null ? null : $sources->get($entry['fact']['audit_event_id']);
            if ($entry === null || $source === null
                || $source->workspace_id !== $operator->workspaceId
                || $source->brand_id !== $operator->brandId
                || $source->action !== 'ai.autonomy.redteam.offline.review'
                || $source->subject_type !== 'offline_redteam_run'
                || $source->subject_id !== $runId || $source->correlation_id !== $runId
                || $source->actor_id === $entry['row']->actor_id
                || ($sequence > 1 && $ordered[$sequence - 1]['fact']['decision'] === 'escalated'
                    && $entry['fact']['decision'] !== 'escalated')
                || $entry['fact']['previous_fingerprint'] !== $previous
                || ! hash_equals($entry['fact']['fingerprint'], self::fingerprint(
                    $runId, $entry['fact']['audit_event_id'], $entry['fact']['decision'],
                    $entry['row']->actor_id, $sequence, $previous,
                ))) {
                throw new InvalidArgumentException('Synthetic triage chain rejected.');
            }
            $previous = $entry['fact']['fingerprint'];
        }

        return $ordered;
    }

    private static function fingerprint(
        string $runId, string $auditEventId, string $decision,
        string $actorId, int $sequence, ?string $previous,
    ): string {
        return hash('sha256', json_encode([
            $runId, $auditEventId, $decision, $actorId, $sequence, $previous,
        ], JSON_THROW_ON_ERROR));
    }

    private static function result(string $runId, string $id, string $auditEventId, string $decision, bool $replayed): array
    {
        return [
            'status' => 'human_triage_recorded', 'decision' => $decision,
            'run_id' => $runId, 'triage_audit_event_id' => $id,
            'source_audit_event_id' => $auditEventId, 'replayed' => $replayed,
            'synthetic_evidence_only' => true, 'incident_resolved' => false,
            'recovery_performed' => false, 'recovery_review_status' => 'blocked_unverified_external_effect',
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
