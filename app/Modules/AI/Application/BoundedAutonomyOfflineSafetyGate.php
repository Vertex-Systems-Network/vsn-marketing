<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomySafetySnapshotSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Deterministic OFFLINE risk, stop and resource preflight.
 *
 * This class is deliberately not a spend/execute admission gate: its source
 * must eventually be coupled to a row-locked reservation and independently
 * authorized approval immediately before any side effect. Both stages still
 * return execution_authorized=false, and no provider or queue is invoked.
 */
final readonly class BoundedAutonomyOfflineSafetyGate
{
    private const array LIMITS = [
        'actions' => 8,
        'tokens' => 1000000,
        'volume' => 1000000,
        'cost_minor' => 1000000000,
        'attempts' => 100000,
    ];

    private const array POLICY_KEYS = [
        'workspace_id', 'brand_id', 'policy_version', 'observed_at_unix',
        'expires_at_unix', 'global_stopped', 'workspace_stopped',
        'max_actions', 'max_tokens', 'max_volume', 'max_cost_minor', 'max_attempts',
        'used_actions', 'used_tokens', 'used_volume', 'reserved_cost_minor',
        'spent_cost_minor', 'used_attempts',
    ];

    public function __construct(private BoundedAutonomySafetySnapshotSource $source) {}

    public function assess(
        TenantContext $scope,
        array $preview,
        array $estimate,
        DateTimeImmutable $at,
        bool $lastSideEffectGate = false,
    ): array {
        $this->assertPreview($scope, $preview);
        $this->assertEstimate($preview, $estimate);
        $stage = $lastSideEffectGate ? 'last_side_effect_preflight' : 'admission_preflight';

        $state = $this->source->current($scope, $at);
        if ($state === null) {
            return $this->result($preview, $stage, 'held_offline', 'independent_policy_unavailable', false);
        }
        $this->assertPolicy($scope, $preview, $state, $at);
        if ($state['global_stopped']) {
            return $this->result($preview, $stage, 'held_offline', 'global_emergency_stop', false);
        }
        if ($state['workspace_stopped']) {
            return $this->result($preview, $stage, 'held_offline', 'workspace_emergency_stop', false);
        }

        foreach (['actions', 'tokens', 'volume', 'attempts'] as $dimension) {
            if ($estimate[$dimension] > $state['max_'.$dimension] - $state['used_'.$dimension]) {
                return $this->result($preview, $stage, 'held_offline', $dimension.'_limit_reached', false);
            }
        }
        if ($estimate['cost_minor'] > $state['max_cost_minor'] - $state['reserved_cost_minor'] - $state['spent_cost_minor']) {
            return $this->result($preview, $stage, 'held_offline', 'cost_limit_reached', false);
        }

        $approvalRequired = false;
        foreach ($preview['actions'] as $action) {
            if ($action['effect'] !== 'read' || $action['risk'] !== 'R0') {
                $approvalRequired = true;
            }
        }

        // Passing this check is evidence for an operator, never authorization.
        // A separate atomic claim/approval and freshly checked kill switch
        // are required if future tasks introduce a real side-effect route.
        return $this->result($preview, $stage, 'offline_preflight_passed', 'reservation_and_final_authority_required', $approvalRequired);
    }

    private function assertPreview(TenantContext $scope, array $preview): void
    {
        if (($preview['status'] ?? null) !== 'preview_ready'
            || ($preview['tenant'] ?? null) !== $scope->toArray()
            || ($preview['execution_authorized'] ?? null) !== false
            || ($preview['stages']['execute'] ?? null) !== 'disabled'
            || ! is_string($preview['run_id'] ?? null)
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $preview['run_id']) !== 1
            || ! is_string($preview['snapshot_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $preview['snapshot_sha256']) !== 1
            || ! is_string($preview['policy_version'] ?? null)
            || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $preview['policy_version']) !== 1
            || ! is_array($preview['actions'] ?? null)
            || ! array_is_list($preview['actions'])
            || $preview['actions'] === [] || count($preview['actions']) > 8) {
            throw new InvalidArgumentException('Untrusted autonomy safety preview rejected.');
        }
        foreach ($preview['actions'] as $action) {
            if (! is_array($action)
                || ! in_array($action['effect'] ?? null, ['read', 'proposal'], true)
                || ! in_array($action['risk'] ?? null, ['R0', 'R1'], true)) {
                throw new InvalidArgumentException('Autonomy risk escalation rejected.');
            }
        }
    }

    private function assertEstimate(array $preview, array $estimate): void
    {
        self::requireKeys($estimate, array_keys(self::LIMITS));
        foreach (self::LIMITS as $name => $maximum) {
            if (! is_int($estimate[$name]) || $estimate[$name] < 0 || $estimate[$name] > $maximum) {
                throw new InvalidArgumentException('Autonomy resource estimate bounds rejected.');
            }
        }
        if ($estimate['actions'] !== count($preview['actions']) || $estimate['attempts'] < 1) {
            throw new InvalidArgumentException('Autonomy action/attempt estimate mismatch.');
        }
    }

    private function assertPolicy(TenantContext $scope, array $preview, array $state, DateTimeImmutable $at): void
    {
        self::requireKeys($state, self::POLICY_KEYS);
        if ($state['workspace_id'] !== $scope->workspaceId
            || $state['brand_id'] !== $scope->brandId
            || $state['policy_version'] !== $preview['policy_version']
            || ! is_bool($state['global_stopped'])
            || ! is_bool($state['workspace_stopped'])
            || ! is_int($state['observed_at_unix'])
            || ! is_int($state['expires_at_unix'])
            || $state['observed_at_unix'] > $at->getTimestamp()
            || $state['observed_at_unix'] < $at->getTimestamp() - 300
            || $state['expires_at_unix'] <= $at->getTimestamp()
            || $state['expires_at_unix'] > $at->getTimestamp() + 3600) {
            throw new InvalidArgumentException('Untrusted autonomy safety policy snapshot rejected.');
        }
        foreach (self::LIMITS as $dimension => $maximum) {
            $limit = $state['max_'.$dimension];
            if (! is_int($limit) || $limit < 0 || $limit > $maximum) {
                throw new InvalidArgumentException('Untrusted autonomy resource ceilings rejected.');
            }
            // Cost is tracked through reserved + spent, never a fake used_cost
            // counter. Validate the two ledgers independently below.
            if ($dimension === 'cost_minor') {
                continue;
            }
            $used = $state['used_'.$dimension];
            if (! is_int($used) || $used < 0 || $used > $limit) {
                throw new InvalidArgumentException('Untrusted autonomy resource counters rejected.');
            }
        }
        if (! is_int($state['reserved_cost_minor']) || ! is_int($state['spent_cost_minor'])
            || $state['reserved_cost_minor'] < 0 || $state['spent_cost_minor'] < 0
            || $state['reserved_cost_minor'] > $state['max_cost_minor']
            || $state['spent_cost_minor'] > $state['max_cost_minor'] - $state['reserved_cost_minor']) {
            throw new InvalidArgumentException('Untrusted autonomy cost reservation counters rejected.');
        }
    }

    private static function requireKeys(array $value, array $required): void
    {
        if (count($value) !== count($required)
            || array_diff(array_keys($value), $required) !== []
            || array_diff($required, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Autonomy safety schema rejected.');
        }
    }

    private function result(array $preview, string $stage, string $status, string $reason, bool $approvalRequired): array
    {
        return [
            'status' => $status,
            'reason_code' => $reason,
            'stage' => $stage,
            'run_id' => $preview['run_id'],
            'snapshot_sha256' => $preview['snapshot_sha256'],
            'independent_approval_required' => $approvalRequired,
            'budget_reservation_complete' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }
}
