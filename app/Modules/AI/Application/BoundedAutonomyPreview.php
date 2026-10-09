<?php

namespace App\Modules\AI\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Offline-only, tenant-bound goal -> plan -> proposal preview.
 *
 * This class never invokes tool handlers, providers, queues, AI routes or billing.
 * Execution/observation/evaluation require separate independently-authorized gates.
 */
final class BoundedAutonomyPreview
{
    private readonly array $tools;

    private readonly array $sources;

    private readonly array $metrics;

    public function __construct(array $toolRegistry, array $allowedSourceIds, array $metricIds, private readonly int $maxActions = 8)
    {
        if ($toolRegistry === [] || $metricIds === [] || $maxActions < 1 || $maxActions > 8) {
            throw new InvalidArgumentException('Autonomy preview policy bounds rejected.');
        }

        foreach ($toolRegistry as $toolId => $entry) {
            if (self::identifier($toolId) === false || is_array($entry) === false
                || array_diff(array_keys($entry), ['effect', 'risk']) !== []
                || in_array($entry['effect'] ?? null, ['read', 'proposal'], true) === false
                || in_array($entry['risk'] ?? null, ['R0', 'R1'], true) === false) {
                throw new InvalidArgumentException('Autonomy registered tool policy rejected.');
            }
        }
        foreach ([$allowedSourceIds, $metricIds] as $ids) {
            if (array_is_list($ids) === false || count($ids) > 100 || count($ids) !== count(array_unique($ids))) {
                throw new InvalidArgumentException('Autonomy evidence registry rejected.');
            }
            foreach ($ids as $id) {
                if (self::identifier($id) === false) {
                    throw new InvalidArgumentException('Autonomy evidence identifier rejected.');
                }
            }
        }

        $this->tools = $toolRegistry;
        $this->sources = array_fill_keys($allowedSourceIds, true);
        $this->metrics = array_fill_keys($metricIds, true);
    }

    public function preview(TenantContext $scope, string $runId, array $goal, array $actions, DateTimeImmutable $at): array
    {
        self::requireKeys($goal, ['workspace_id', 'brand_id', 'policy_version', 'purpose', 'metric_id', 'target_count', 'expires_at_unix']);
        if (self::identifier($runId) === false
            || $goal['workspace_id'] !== $scope->workspaceId
            || $goal['brand_id'] !== $scope->brandId
            || self::identifier($goal['policy_version']) === false
            || in_array($goal['purpose'], ['campaign_optimization', 'content_quality'], true) === false
            || is_string($goal['metric_id']) === false || isset($this->metrics[$goal['metric_id']]) === false
            || is_int($goal['target_count']) === false || $goal['target_count'] < 1 || $goal['target_count'] > 1000000
            || is_int($goal['expires_at_unix']) === false || $goal['expires_at_unix'] <= $at->getTimestamp()
            || $goal['expires_at_unix'] > $at->getTimestamp() + 86400) {
            throw new InvalidArgumentException('Autonomy goal / tenant policy rejected.');
        }
        if (array_is_list($actions) === false || $actions === [] || count($actions) > $this->maxActions) {
            throw new InvalidArgumentException('Autonomy plan action bound rejected.');
        }

        $normalized = [];
        $seen = [];
        foreach ($actions as $action) {
            if (is_array($action) === false) {
                throw new InvalidArgumentException('Autonomy action must be a typed object.');
            }
            self::requireKeys($action, ['tool_id', 'arguments_sha256', 'source_ids', 'reason_code']);
            $toolId = $action['tool_id'];
            $registered = is_string($toolId) ? ($this->tools[$toolId] ?? null) : null;
            $digest = $action['arguments_sha256'];
            $refs = $action['source_ids'];
            if (is_array($registered) === false || is_string($digest) === false || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || in_array($action['reason_code'], ['metric_review', 'campaign_draft', 'content_review'], true) === false
                || is_array($refs) === false || array_is_list($refs) === false || $refs === [] || count($refs) > 8
                || count($refs) !== count(array_unique($refs))) {
                throw new InvalidArgumentException('Autonomy action evidence rejected.');
            }
            foreach ($refs as $ref) {
                if (is_string($ref) === false || isset($this->sources[$ref]) === false) {
                    throw new InvalidArgumentException('Autonomy action source not independently registered.');
                }
            }
            $dedupe = $toolId.':'.$digest;
            if (isset($seen[$dedupe])) {
                throw new InvalidArgumentException('Duplicate autonomy action rejected.');
            }
            $seen[$dedupe] = true;
            $normalized[] = [
                'tool_id' => $toolId,
                'effect' => $registered['effect'],
                'risk' => $registered['risk'],
                'arguments_sha256' => $digest,
                'source_ids' => $refs,
                'reason_code' => $action['reason_code'],
            ];
        }

        $snapshot = [
            'tenant' => $scope->toArray(),
            'run_id' => $runId,
            'goal' => $goal,
            'actions' => $normalized,
            'mode' => 'offline_proposal_only',
        ];

        return [
            'status' => 'preview_ready',
            'execution_authorized' => false,
            'run_id' => $runId,
            'tenant' => $scope->toArray(),
            'policy_version' => $goal['policy_version'],
            'snapshot_sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'actions' => $normalized,
            'stages' => [
                'goal' => 'validated',
                'plan' => 'validated',
                'propose' => 'offline_preview',
                'execute' => 'disabled',
                'observe' => 'unavailable',
                'evaluate' => 'not_run',
            ],
        ];
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }

    private static function requireKeys(array $value, array $keys): void
    {
        if (count($value) !== count($keys) || array_diff(array_keys($value), $keys) !== []
            || array_diff($keys, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Unregistered autonomy field rejected.');
        }
    }
}
