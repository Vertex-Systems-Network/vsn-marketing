<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\Experiments\Domain\ExperimentAccess;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Permission-scoped, read-only synthetic incident audit review for operators.
 *
 * Only the bounded canonical audit facts are exposed. This is NOT production
 * incident verification, human recovery approval or live execution authority.
 */
final readonly class DatabaseBoundedAutonomyOfflineRedTeamMonitor
{
    private const string ACTION = 'ai.autonomy.redteam.offline.review';

    private const array FIELDS = [
        'run_id', 'sequence', 'previous_review_fingerprint',
        'review_fingerprint', 'source_manifest_sha256', 'recorded_status',
        'recorded_reason', 'case_count', 'severity_counts', 'category_counts',
        'synthetic_evidence_only', 'production_incident_verified',
        'recovery_performed',
    ];

    private const array CATEGORIES = [
        'goal_hijack', 'tool_misuse', 'self_approval', 'cross_tenant',
        'quota_race', 'emergency_stop', 'provider_replay',
        'metric_spoofing', 'exfiltration',
    ];

    private const array SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public function __construct(private ExperimentAccess $access) {}

    public function inspect(TenantContext $operator, string $runId): array
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId) !== 1
            || strlen($operator->actorId) > 64
            || ! $this->access->allows($operator, PermissionCatalog::CAMPAIGN_READ)) {
            throw new InvalidArgumentException('Operator synthetic incident monitoring scope denied.');
        }

        if (DB::table('workspaces')->where('id', $operator->workspaceId)
            ->where('organization_id', $operator->organizationId)
            ->first() === null) {
            throw new InvalidArgumentException('Foreign synthetic incident workspace denied.');
        }

        $rows = DB::table('audit_events')
            ->where('workspace_id', $operator->workspaceId)
            ->where('action', self::ACTION)
            ->where('subject_type', 'offline_redteam_run')
            ->where('subject_id', $runId)
            ->limit(1025)
            ->get();

        if ($rows->count() > 1024) {
            throw new InvalidArgumentException('Offline incident audit exceeds bounded history.');
        }
        if ($rows->isEmpty()) {
            return self::result($runId, null, 0, 'held_offline', 'redteam_audit_unavailable');
        }

        $ordered = [];
        foreach ($rows as $row) {
            if ($row->brand_id !== $operator->brandId || ! is_string($row->actor_id)
                || $row->actor_id === '' || $row->correlation_id !== $runId) {
                throw new InvalidArgumentException('Untrusted cross-brand red-team audit provenance.');
            }

            $evidence = json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($evidence)
                || count($evidence) !== count(self::FIELDS)
                || array_diff(array_keys($evidence), self::FIELDS) !== []
                || array_diff(self::FIELDS, array_keys($evidence)) !== []
                || ($evidence['run_id'] ?? null) !== $runId
                || ! is_int($evidence['sequence'])
                || $evidence['sequence'] < 1 || $evidence['sequence'] > 1024
                || isset($ordered[$evidence['sequence']])
                || ! self::digest($evidence['review_fingerprint'])
                || ! (is_null($evidence['previous_review_fingerprint'])
                    || self::digest($evidence['previous_review_fingerprint']))
                || ! (is_null($evidence['source_manifest_sha256'])
                    || self::digest($evidence['source_manifest_sha256']))
                || $evidence['synthetic_evidence_only'] !== true
                || $evidence['production_incident_verified'] !== false
                || $evidence['recovery_performed'] !== false
                || ! is_int($evidence['case_count'])
                || $evidence['case_count'] < 0 || $evidence['case_count'] > 128
                || ! self::counts($evidence['severity_counts'], self::SEVERITIES)
                || ! self::counts($evidence['category_counts'], self::CATEGORIES)
                || array_sum($evidence['severity_counts']) !== $evidence['case_count']
                || array_sum($evidence['category_counts']) !== $evidence['case_count']) {
                throw new InvalidArgumentException('Corrupt synthetic incident audit evidence.');
            }

            $status = $evidence['recorded_status'];
            $reason = $evidence['recorded_reason'];
            $ready = $status === 'offline_redteam_review_ready'
                && $reason === 'human_incident_triage_required'
                && $evidence['case_count'] >= count(self::CATEGORIES)
                && min($evidence['category_counts']) > 0
                && self::digest($evidence['source_manifest_sha256']);
            $held = $status === 'held_offline'
                && (
                    ($evidence['case_count'] === 0
                        && $evidence['source_manifest_sha256'] === null
                        && in_array($reason, [
                            'redteam_evidence_unavailable',
                            'redteam_coverage_incomplete',
                            'unresolved_redteam_safety_case',
                        ], true))
                    || ($reason === 'prior_unresolved_redteam_safety_hold'
                        && $evidence['case_count'] > 0
                        && self::digest($evidence['source_manifest_sha256']))
                );

            if (! $ready && ! $held) {
                throw new InvalidArgumentException('Synthetic incident audit contains unsupported resolution claim.');
            }
            $ordered[$evidence['sequence']] = ['row' => $row, 'fact' => $evidence];
        }

        $previous = null;
        $priorHeld = false;
        for ($sequence = 1; $sequence <= count($ordered); $sequence++) {
            $entry = $ordered[$sequence] ?? null;
            if ($entry === null || $entry['fact']['previous_review_fingerprint'] !== $previous
                || ($priorHeld && $entry['fact']['recorded_status'] !== 'held_offline')) {
                throw new InvalidArgumentException('Synthetic incident history has a gap, broken chain or lost hold.');
            }
            $previous = $entry['fact']['review_fingerprint'];
            $priorHeld = $entry['fact']['recorded_status'] === 'held_offline';
        }

        $latest = $ordered[count($ordered)];

        return self::result(
            $runId, $latest['row']->id, count($ordered),
            $latest['fact']['recorded_status'], $latest['fact']['recorded_reason'],
            $latest['fact']['case_count'], $latest['fact']['severity_counts'],
            $latest['fact']['category_counts'],
        );
    }

    private static function result(
        string $runId,
        ?string $eventId,
        int $sequence,
        string $status,
        string $reason,
        int $caseCount = 0,
        ?array $severities = null,
        ?array $categories = null,
    ): array {
        return [
            'status' => $status,
            'reason_code' => $reason,
            'run_id' => $runId,
            'latest_audit_event_id' => $eventId,
            'audit_sequence' => $sequence,
            'case_count' => $caseCount,
            'severity_counts' => $severities ?? array_fill_keys(self::SEVERITIES, 0),
            'category_counts' => $categories ?? array_fill_keys(self::CATEGORIES, 0),
            'audit_history_integrity_checked' => $eventId !== null,
            'human_triage_required' => true,
            'synthetic_evidence_only' => true,
            'production_incident_verified' => false,
            'incident_resolved' => false,
            'recovery_performed' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
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
}
