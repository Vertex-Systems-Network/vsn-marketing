<?php

namespace App\Modules\AI\Application;

use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Fail-closed, typed monotonic transition contract for offline autonomy.
 *
 * Applied only to server-validated previews, durable proposal receipts and
 * independently sourced observation results. This never runs tools/providers.
 * A held observation is transient, not a fabricated persisted evaluation.
 */
final class BoundedAutonomyOfflineLifecycle
{
    private const array SCHEMAS = [
        'preview_ready' => ['status', 'execution_authorized', 'run_id', 'tenant', 'policy_version', 'snapshot_sha256', 'actions', 'stages'],
        'recorded_offline' => ['status', 'run_id', 'tenant', 'snapshot_sha256', 'execution_authorized', 'stages'],
        'awaiting_verified_observation' => ['status', 'run_id', 'tenant', 'snapshot_sha256', 'reason_code', 'execution_authorized', 'promotion_authorized'],
        'evaluated_offline' => ['status', 'run_id', 'tenant', 'snapshot_sha256', 'observation_sha256', 'evidence_sha256', 'decision', 'observed_count', 'causal_lift_proven', 'execution_authorized', 'promotion_authorized'],
    ];

    private const array EDGES = [
        'preview_ready' => ['recorded_offline'],
        'recorded_offline' => ['awaiting_verified_observation', 'evaluated_offline'],
        'awaiting_verified_observation' => ['evaluated_offline'],
        'evaluated_offline' => [],
    ];

    public function assertTransition(TenantContext $scope, array $from, array $to): void
    {
        $this->assertState($scope, $from);
        $this->assertState($scope, $to);

        if (! in_array($to['status'], self::EDGES[$from['status']], true)
            || $from['run_id'] !== $to['run_id']
            || $from['snapshot_sha256'] !== $to['snapshot_sha256']) {
            throw new InvalidArgumentException('Offline autonomy lifecycle transition denied.');
        }

        if ($to['status'] === 'recorded_offline' && $to['stages'] !== $from['stages']) {
            throw new InvalidArgumentException('Offline autonomy receipt stages changed.');
        }
    }

    private function assertState(TenantContext $scope, array $state): void
    {
        $status = $state['status'] ?? null;
        $schema = is_string($status) ? (self::SCHEMAS[$status] ?? null) : null;
        if (! is_array($schema)
            || count($state) !== count($schema)
            || array_diff(array_keys($state), $schema) !== []
            || array_diff($schema, array_keys($state)) !== []
            || ($state['tenant'] ?? null) !== $scope->toArray()
            || ($state['execution_authorized'] ?? null) !== false
            || ! is_string($state['run_id'] ?? null)
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $state['run_id']) !== 1
            || ! self::digest($state['snapshot_sha256'] ?? null)) {
            throw new InvalidArgumentException('Untrusted offline autonomy lifecycle state rejected.');
        }

        if ($status === 'preview_ready' || $status === 'recorded_offline') {
            $expected = [
                'goal' => 'validated',
                'plan' => 'validated',
                'propose' => 'offline_preview',
                'execute' => 'disabled',
                'observe' => 'unavailable',
                'evaluate' => 'not_run',
            ];
            if (($state['stages'] ?? null) !== $expected) {
                throw new InvalidArgumentException('Offline autonomy lifecycle stages rejected.');
            }
        }
        if ($status === 'preview_ready') {
            if (! is_string($state['policy_version'] ?? null)
                || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $state['policy_version']) !== 1
                || ! is_array($state['actions']) || ! array_is_list($state['actions'])
                || $state['actions'] === [] || count($state['actions']) > 8) {
                throw new InvalidArgumentException('Offline autonomy preview type rejected.');
            }
            foreach ($state['actions'] as $action) {
                if (! is_array($action)
                    || ! in_array($action['effect'] ?? null, ['read', 'proposal'], true)
                    || ! in_array($action['risk'] ?? null, ['R0', 'R1'], true)) {
                    throw new InvalidArgumentException('Offline autonomy effect escalation rejected.');
                }
            }
        }

        if ($status === 'awaiting_verified_observation' || $status === 'evaluated_offline') {
            if (($state['promotion_authorized'] ?? null) !== false) {
                throw new InvalidArgumentException('Offline autonomy promotion escalation rejected.');
            }
        }
        if ($status === 'awaiting_verified_observation'
            && ($state['reason_code'] ?? null) !== 'no_independently_verified_evidence') {
            throw new InvalidArgumentException('Unrecognized offline observation hold reason.');
        }
        if ($status === 'evaluated_offline'
            && (! self::digest($state['observation_sha256'] ?? null)
                || ! self::digest($state['evidence_sha256'] ?? null)
                || ! in_array($state['decision'] ?? null, ['target_met_operator_review', 'hold_below_target'], true)
                || ! is_int($state['observed_count'] ?? null)
                || $state['observed_count'] < 0 || $state['observed_count'] > 1000000
                || ($state['causal_lift_proven'] ?? null) !== false)) {
            throw new InvalidArgumentException('Untrusted offline autonomy evaluation state.');
        }
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
