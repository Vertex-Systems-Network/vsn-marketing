<?php

namespace App\Modules\AI\Application;

use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Derives both preview and durable offline receipt from the same trusted
 * analytics evidence, with no external tool or AI-generated authorization.
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
        [$policy, $goal, $actions] = $this->prepare($actor, $authorizedReports, $reportId, $targetCount, $runId, $at);

        return $policy->preview($actor, $runId, $goal, $actions, $at);
    }

    public function record(
        TenantContext $actor,
        array $authorizedReports,
        string $reportId,
        int $targetCount,
        string $runId,
        DateTimeImmutable $at,
        IdempotentExecutor $idempotency,
    ): array {
        [$policy, $goal, $actions] = $this->prepare($actor, $authorizedReports, $reportId, $targetCount, $runId, $at);

        return (new BoundedAutonomyOfflineReceipt($policy, $idempotency))->record(
            $actor, $runId, $goal, $actions, $at,
        );
    }

    private function prepare(
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

        $policy = new BoundedAutonomyPreview(
            ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
            [$reportId],
            ['snapshot_review_count'],
            1,
        );

        // The expiration is derived from the issuance time by the caller.
        // prepare() intentionally does not trust a client-supplied expiry.
        $goal = [
            'workspace_id' => $actor->workspaceId,
            'brand_id' => $actor->brandId,
            'policy_version' => 'v1',
            'purpose' => 'campaign_optimization',
            'metric_id' => 'snapshot_review_count',
            'target_count' => $targetCount,
            'expires_at_unix' => $at->getTimestamp() + 3600,
        ];

        return [$policy, $goal, $actions];
    }
}
