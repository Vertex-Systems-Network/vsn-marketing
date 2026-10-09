<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyRollbackOutcomeSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Human-readable, OFFLINE rollback reconciliation evidence.
 * A rollback of confirmed external effects cannot be asserted by this class.
 * All results are advice/hold only with no refund, retry, send or promotion.
 */
final readonly class BoundedAutonomyOfflineRollbackReview
{
    private const array KEYS = [
        'tenant', 'run_id', 'snapshot_sha256', 'source_sha256',
        'observed_at_unix', 'attempted_at_unix', 'callback_count',
        'outcome', 'outcome_verified_independently', 'late_callback',
        'duplicate_callback', 'external_cost_minor',
    ];

    public function __construct(private BoundedAutonomyRollbackOutcomeSource $source) {}

    public function inspect(
        TenantContext $actor,
        string $runId,
        string $snapshotSha256,
        DateTimeImmutable $at,
    ): array {
        if (! self::id($runId) || ! self::digest($snapshotSha256)) {
            throw new InvalidArgumentException('Rollback requires typed immutable run evidence.');
        }

        $record = $this->source->latest($actor, $runId, $at);
        if ($record === null) {
            return $this->held($runId, $snapshotSha256, 'provider_outcome_unknown');
        }
        self::keys($record, self::KEYS);
        if ($record['tenant'] !== $actor->toArray()
            || $record['run_id'] !== $runId
            || $record['snapshot_sha256'] !== $snapshotSha256
            || ! self::digest($record['source_sha256'])
            || ! is_int($record['observed_at_unix'])
            || ! is_int($record['attempted_at_unix'])
            || $record['observed_at_unix'] > $at->getTimestamp()
            || $record['observed_at_unix'] < $at->getTimestamp() - 3600
            || $record['attempted_at_unix'] > $record['observed_at_unix']
            || $record['attempted_at_unix'] < $record['observed_at_unix'] - 3600
            || ! is_int($record['callback_count']) || $record['callback_count'] < 0
            || $record['callback_count'] > 100000
            || ! is_int($record['external_cost_minor'])
            || $record['external_cost_minor'] < 0
            || $record['external_cost_minor'] > 1000000000
            || ! is_bool($record['late_callback'])
            || ! is_bool($record['duplicate_callback'])
            || ! is_bool($record['outcome_verified_independently'])
            || ! in_array($record['outcome'], ['unknown', 'confirmed_not_applied', 'confirmed_applied'], true)) {
            throw new InvalidArgumentException('Untrusted rollback provenance or mutable provider outcome.');
        }

        if (! $record['outcome_verified_independently'] || $record['outcome'] === 'unknown') {
            return $this->held($runId, $snapshotSha256, 'provider_outcome_unverified');
        }
        if ($record['late_callback'] || $record['duplicate_callback'] || $record['callback_count'] > 1) {
            return $this->held($runId, $snapshotSha256, 'late_or_duplicate_provider_outcome');
        }
        if ($record['outcome'] === 'confirmed_applied') {
            return $this->held($runId, $snapshotSha256, 'irreversible_external_effect_requires_manual_recovery');
        }
        if ($record['external_cost_minor'] > 0) {
            return $this->held($runId, $snapshotSha256, 'nonzero_provider_cost_requires_independent_settlement');
        }

        // An independently verified non-effect may be reviewed as a candidate
        // for an OFFLINE rollback. Actual rollback is intentionally absent.
        return [
            'status' => 'offline_rollback_review_ready',
            'reason_code' => 'human_rollback_authority_required',
            'run_id' => $runId,
            'snapshot_sha256' => $snapshotSha256,
            'external_outcome_verified' => true,
            'rollback_performed' => false,
            'refund_authorized' => false,
            'retry_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private function held(string $runId, string $snapshotSha256, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => $runId,
            'snapshot_sha256' => $snapshotSha256,
            'external_outcome_verified' => false,
            'rollback_performed' => false,
            'refund_authorized' => false,
            'retry_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function keys(array $input, array $expected): void
    {
        if (count($input) !== count($expected)
            || array_diff(array_keys($input), $expected) !== []
            || array_diff($expected, array_keys($input)) !== []) {
            throw new InvalidArgumentException('Untrusted provider rollback evidence shape.');
        }
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function id(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }
}
