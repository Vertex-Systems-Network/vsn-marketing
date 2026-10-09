<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyApprovalSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Offline-only matching of current, independently authorized approval to an
 * immutable exact plan/audience/content/destination/cost envelope.
 *
 * Never authorizes external execution, billing, sending or promotion.
 * An independent DB-backed role verifier and atomic final admission remain
 * necessary before a future privileged side effect could even be considered.
 */
final readonly class BoundedAutonomyOfflineApprovalReview
{
    private const array BINDING_KEYS = [
        'audience_sha256', 'content_sha256', 'destination_sha256',
        'max_cost_minor', 'max_volume', 'not_before_unix', 'expires_at_unix',
    ];

    private const array DECISION_KEYS = [
        'decision_id', 'workspace_id', 'brand_id', 'run_id', 'snapshot_sha256',
        'policy_version', 'audience_sha256', 'content_sha256',
        'destination_sha256', 'max_cost_minor', 'max_volume',
        'not_before_unix', 'expires_at_unix', 'approved_at_unix',
        'approver_id', 'outcome',
    ];

    public function __construct(private BoundedAutonomyApprovalSource $source) {}

    public function inspect(
        TenantContext $scope,
        array $preview,
        array $binding,
        DateTimeImmutable $at,
    ): array {
        if (($preview['status'] ?? null) !== 'preview_ready'
            || ($preview['tenant'] ?? null) !== $scope->toArray()
            || ($preview['execution_authorized'] ?? null) !== false
            || ($preview['stages']['execute'] ?? null) !== 'disabled'
            || ! self::digest($preview['snapshot_sha256'] ?? null)
            || ! self::identifier($preview['run_id'] ?? null)
            || ! self::identifier($preview['policy_version'] ?? null)) {
            throw new InvalidArgumentException('Approval review requires a validated tenant-owned offline preview.');
        }
        self::keys($binding, self::BINDING_KEYS);
        foreach (['audience_sha256', 'content_sha256', 'destination_sha256'] as $key) {
            if (! self::digest($binding[$key])) {
                throw new InvalidArgumentException('Untrusted approval artifact fingerprint.');
            }
        }
        foreach (['max_cost_minor' => 1000000000, 'max_volume' => 1000000] as $key => $max) {
            if (! is_int($binding[$key]) || $binding[$key] < 0 || $binding[$key] > $max) {
                throw new InvalidArgumentException('Untrusted approval resource ceiling.');
            }
        }
        if (! is_int($binding['not_before_unix']) || ! is_int($binding['expires_at_unix'])
            || $binding['not_before_unix'] > $at->getTimestamp()
            || $binding['expires_at_unix'] <= $at->getTimestamp()
            || $binding['expires_at_unix'] > $at->getTimestamp() + 86400) {
            throw new InvalidArgumentException('Untrusted approval time window.');
        }

        $latest = $this->source->latest($scope, $preview['run_id'], $at);
        if ($latest === null) {
            return $this->held($preview, 'independent_approval_unavailable');
        }
        self::keys($latest, self::DECISION_KEYS);
        if (! self::identifier($latest['decision_id'])
            || ! self::identifier($latest['approver_id'])
            || ! is_int($latest['approved_at_unix'])
            || ! is_int($latest['not_before_unix'])
            || ! is_int($latest['expires_at_unix'])
            || ! is_int($latest['max_cost_minor'])
            || ! is_int($latest['max_volume'])
            || ! in_array($latest['outcome'], ['approved', 'rejected', 'revoked'], true)) {
            throw new InvalidArgumentException('Untrusted autonomy approval decision schema.');
        }

        foreach ([
            'workspace_id' => $scope->workspaceId,
            'brand_id' => $scope->brandId,
            'run_id' => $preview['run_id'],
            'snapshot_sha256' => $preview['snapshot_sha256'],
            'policy_version' => $preview['policy_version'],
        ] as $key => $expected) {
            if ($latest[$key] !== $expected) {
                return $this->held($preview, 'approval_scope_or_snapshot_changed');
            }
        }
        foreach (self::BINDING_KEYS as $key) {
            if ($latest[$key] !== $binding[$key]) {
                return $this->held($preview, 'approval_binding_changed');
            }
        }
        if ($latest['approver_id'] === $scope->actorId) {
            return $this->held($preview, 'self_approval_forbidden');
        }
        if ($latest['approved_at_unix'] > $at->getTimestamp()
            || $latest['approved_at_unix'] < $binding['not_before_unix']
            || $latest['approved_at_unix'] >= $binding['expires_at_unix']) {
            return $this->held($preview, 'approval_not_current');
        }
        if ($latest['outcome'] !== 'approved') {
            return $this->held($preview, $latest['outcome'] === 'revoked' ? 'approval_revoked' : 'approval_rejected');
        }

        // This is operator-readable evidence only. No external authority.
        return [
            'status' => 'approval_matched_offline',
            'reason_code' => 'independent_last_gate_required',
            'run_id' => $preview['run_id'],
            'snapshot_sha256' => $preview['snapshot_sha256'],
            'decision_id' => $latest['decision_id'],
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private function held(array $preview, string $reason): array
    {
        return [
            'status' => 'approval_held_offline',
            'reason_code' => $reason,
            'run_id' => $preview['run_id'],
            'snapshot_sha256' => $preview['snapshot_sha256'],
            'decision_id' => null,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function keys(array $value, array $keys): void
    {
        if (count($value) !== count($keys)
            || array_diff(array_keys($value), $keys) !== []
            || array_diff($keys, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Unregistered approval field rejected.');
        }
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }
}
