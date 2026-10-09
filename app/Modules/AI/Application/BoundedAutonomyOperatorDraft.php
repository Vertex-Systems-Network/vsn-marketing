<?php

namespace App\Modules\AI\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Creates a read-only operator preview from the caller's currently authorized
 * analytics snapshot list. A client cannot register its own evidence source,
 * tool, policy version, tenant, permission, or external side effect.
 */
final readonly class BoundedAutonomyOperatorDraft
{
    public function create(
        TenantContext $actor,
        array $authorizedReports,
        string $reportId,
        int $targetCount,
        string $runId,
        DateTimeImmutable $at,
    ): array {
        if (preg_match('/^[a-f0-9-]{36}$/D', $reportId) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', $runId) !== 1
            || $targetCount < 1 || $targetCount > 1000000) {
            throw new InvalidArgumentException('Offline autonomy draft bounds rejected.');
        }
        $fingerprint = null;
        foreach ($authorizedReports as $report) {
            if (is_array($report) && ($report['id'] ?? null) === $reportId) {
                $fingerprint = $report['fingerprint'] ?? null;
                break;
            }
        }
        if (! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Authorized immutable analytics evidence required.');
        }

        $actions = [[
            'tool_id' => 'analytics_read',
            'arguments_sha256' => hash('sha256', json_encode([
                'workspace' => $actor->workspaceId,
                'brand' => $actor->brandId,
                'snapshot_id' => $reportId,
                'snapshot_fingerprint' => $fingerprint,
                'metric' => 'snapshot_review_count',
            ], JSON_THROW_ON_ERROR)),
            'source_ids' => [$reportId],
            'reason_code' => 'metric_review',
        ]];

        return (new BoundedAutonomyPreview(
            ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
            [$reportId],
            ['snapshot_review_count'],
            1,
        ))->preview($actor, $runId, [
            'workspace_id' => $actor->workspaceId,
            'brand_id' => $actor->brandId,
            'policy_version' => 'v1',
            'purpose' => 'campaign_optimization',
            'metric_id' => 'snapshot_review_count',
            'target_count' => $targetCount,
            'expires_at_unix' => $at->getTimestamp() + 3600,
        ], $actions, $at);
    }
}
