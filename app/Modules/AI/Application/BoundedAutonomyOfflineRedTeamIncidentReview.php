<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyRedTeamEvidenceSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Read-only, redact-by-construction summary of OFFLINE adversarial fixtures.
 *
 * Never accepts raw prompt text, credentials, recipient data, live incident
 * claims, provider callbacks or tool authority. A source checksum checks
 * consistency, not third-party origin or a production outcome.
 */
final readonly class BoundedAutonomyOfflineRedTeamIncidentReview
{
    private const array ENVELOPE_FIELDS = [
        'tenant', 'run_id', 'evidence_class', 'captured_at_unix',
        'expires_at_unix', 'complete', 'findings', 'source_manifest_sha256',
    ];

    private const array FINDING_FIELDS = [
        'case_id', 'category', 'severity', 'outcome', 'payload_sha256',
        'observed_at_unix',
    ];

    private const array CATEGORIES = [
        'goal_hijack', 'tool_misuse', 'self_approval', 'cross_tenant',
        'quota_race', 'emergency_stop', 'provider_replay',
        'metric_spoofing', 'exfiltration',
    ];

    private const array SEVERITIES = ['low', 'medium', 'high', 'critical'];

    private const array OUTCOMES = ['blocked', 'held', 'unresolved'];

    public function __construct(private BoundedAutonomyRedTeamEvidenceSource $source) {}

    public function inspect(TenantContext $scope, string $runId, DateTimeImmutable $at): array
    {
        if (! self::identifier($runId)
            || $scope->actorId === '' || $scope->workspaceId === '' || $scope->brandId === '') {
            throw new InvalidArgumentException('Invalid offline red-team review scope.');
        }

        $evidence = $this->source->latest($scope, $runId, $at);
        if ($evidence === null) {
            return self::held($runId, 'redteam_evidence_unavailable');
        }

        self::requireKeys($evidence, self::ENVELOPE_FIELDS);
        if ($evidence['tenant'] !== $scope->toArray()
            || $evidence['run_id'] !== $runId
            || $evidence['evidence_class'] !== 'synthetic_offline'
            || ! is_int($evidence['captured_at_unix'])
            || $evidence['captured_at_unix'] > $at->getTimestamp()
            || $evidence['captured_at_unix'] < $at->getTimestamp() - 3600
            || ! is_int($evidence['expires_at_unix'])
            || $evidence['expires_at_unix'] <= $at->getTimestamp()
            || $evidence['expires_at_unix'] > $at->getTimestamp() + 3600
            || ! is_bool($evidence['complete'])
            || ! is_array($evidence['findings'])
            || ! array_is_list($evidence['findings'])
            || count($evidence['findings']) > 128
            || ! self::digest($evidence['source_manifest_sha256'])) {
            throw new InvalidArgumentException('Untrusted red-team fixture envelope.');
        }

        $ids = [];
        $severityCounts = array_fill_keys(self::SEVERITIES, 0);
        $categoryCounts = array_fill_keys(self::CATEGORIES, 0);
        $unresolved = false;

        foreach ($evidence['findings'] as $finding) {
            if (! is_array($finding)) {
                throw new InvalidArgumentException('Malformed red-team finding.');
            }
            self::requireKeys($finding, self::FINDING_FIELDS);
            if (! self::identifier($finding['case_id'])
                || ! in_array($finding['category'], self::CATEGORIES, true)
                || ! in_array($finding['severity'], self::SEVERITIES, true)
                || ! in_array($finding['outcome'], self::OUTCOMES, true)
                || ! self::digest($finding['payload_sha256'])
                || ! is_int($finding['observed_at_unix'])
                || $finding['observed_at_unix'] > $evidence['captured_at_unix']
                || $finding['observed_at_unix'] < $evidence['captured_at_unix'] - 3600
                || $finding['observed_at_unix'] < $at->getTimestamp() - 3600
                || isset($ids[$finding['case_id']])) {
                throw new InvalidArgumentException('Untrusted or replayed red-team case.');
            }

            $ids[$finding['case_id']] = true;
            $severityCounts[$finding['severity']]++;
            $categoryCounts[$finding['category']]++;
            $unresolved = $unresolved || $finding['outcome'] === 'unresolved';
        }

        // Hash binds immutable, ordered fixtures. The caller cannot use this
        // checksum as provider authentication or proof of real-world defense.
        $expected = hash('sha256', json_encode([
            'tenant' => $scope->toArray(),
            'run_id' => $runId,
            'evidence_class' => $evidence['evidence_class'],
            'captured_at_unix' => $evidence['captured_at_unix'],
            'expires_at_unix' => $evidence['expires_at_unix'],
            'complete' => $evidence['complete'],
            'findings' => $evidence['findings'],
        ], JSON_THROW_ON_ERROR));
        if (! hash_equals($expected, $evidence['source_manifest_sha256'])) {
            throw new InvalidArgumentException('Tampered red-team evidence manifest.');
        }

        if (! $evidence['complete'] || $evidence['findings'] === []) {
            return self::held($runId, 'redteam_coverage_incomplete');
        }
        if ($unresolved) {
            return self::held($runId, 'unresolved_redteam_safety_case');
        }

        // Counts only: no raw payload, prompt, secret, email, provider outcome
        // or falsely recorded production incident can escape this boundary.
        return [
            'status' => 'offline_redteam_review_ready',
            'reason_code' => 'human_incident_triage_required',
            'run_id' => $runId,
            'source_manifest_sha256' => $expected,
            'case_count' => count($evidence['findings']),
            'severity_counts' => $severityCounts,
            'category_counts' => $categoryCounts,
            'synthetic_evidence_only' => true,
            'production_incident_verified' => false,
            'incident_recorded' => false,
            'recovery_performed' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function held(string $runId, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => $runId,
            'source_manifest_sha256' => null,
            'case_count' => 0,
            'severity_counts' => array_fill_keys(self::SEVERITIES, 0),
            'category_counts' => array_fill_keys(self::CATEGORIES, 0),
            'synthetic_evidence_only' => true,
            'production_incident_verified' => false,
            'incident_recorded' => false,
            'recovery_performed' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function requireKeys(array $record, array $expected): void
    {
        if (count($record) !== count($expected)
            || array_diff(array_keys($record), $expected) !== []
            || array_diff($expected, array_keys($record)) !== []) {
            throw new InvalidArgumentException('Unregistered red-team evidence field.');
        }
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
