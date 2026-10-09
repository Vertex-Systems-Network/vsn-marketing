<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyVerifiedProviderAttemptSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Offline-only reconciliation of individually verified provider attempts.
 *
 * A single success assertion, aggregate conversion claim, delayed callback,
 * duplicate operation or incomplete attempt list can NEVER claim rollback.
 * This is not a provider adapter and has no effectful method.
 */
final readonly class BoundedAutonomyOfflineProviderAttemptReview
{
    private const array ENVELOPE = [
        'tenant', 'run_id', 'snapshot_sha256', 'source_manifest_sha256',
        'captured_at_unix', 'expires_at_unix', 'complete', 'attempts',
    ];

    private const array ATTEMPT = [
        'operation_id', 'idempotency_key', 'receipt_sha256', 'attempted_at_unix',
        'outcome', 'verified_independently', 'late', 'callback_count',
        'external_cost_minor', 'irreversible',
    ];

    public function __construct(private BoundedAutonomyVerifiedProviderAttemptSource $source) {}

    public function inspect(
        TenantContext $scope,
        string $runId,
        string $snapshotSha256,
        DateTimeImmutable $at,
    ): array {
        if (! self::identifier($runId) || ! self::digest($snapshotSha256)
            || $scope->actorId === '' || $scope->workspaceId === '') {
            throw new InvalidArgumentException('Invalid offline provider reconciliation scope.');
        }

        $envelope = $this->source->latest($scope, $runId, $at);
        if ($envelope === null) {
            return $this->hold($runId, $snapshotSha256, 'provider_attempt_source_unavailable');
        }

        self::keys($envelope, self::ENVELOPE);
        if ($envelope['tenant'] !== $scope->toArray()
            || $envelope['run_id'] !== $runId
            || $envelope['snapshot_sha256'] !== $snapshotSha256
            || ! self::digest($envelope['source_manifest_sha256'])
            || ! is_int($envelope['captured_at_unix'])
            || ! is_int($envelope['expires_at_unix'])
            || $envelope['captured_at_unix'] > $at->getTimestamp()
            || $envelope['captured_at_unix'] < $at->getTimestamp() - 3600
            || $envelope['expires_at_unix'] <= $at->getTimestamp()
            || $envelope['expires_at_unix'] > $at->getTimestamp() + 3600
            || ! is_bool($envelope['complete'])
            || ! is_array($envelope['attempts'])
            || ! array_is_list($envelope['attempts'])
            || count($envelope['attempts']) > 128) {
            throw new InvalidArgumentException('Provider attempt provenance envelope rejected.');
        }

        $operations = [];
        $idempotency = [];
        $flag = null;
        foreach ($envelope['attempts'] as $attempt) {
            if (! is_array($attempt)) {
                throw new InvalidArgumentException('Provider attempt must be a typed record.');
            }
            self::keys($attempt, self::ATTEMPT);
            if (! self::identifier($attempt['operation_id'])
                || ! self::identifier($attempt['idempotency_key'])
                || ! self::digest($attempt['receipt_sha256'])
                || ! is_int($attempt['attempted_at_unix'])
                || $attempt['attempted_at_unix'] > $envelope['captured_at_unix']
                || $attempt['attempted_at_unix'] > $at->getTimestamp()
                || ! in_array($attempt['outcome'], ['unknown', 'confirmed_applied', 'confirmed_not_applied'], true)
                || ! is_bool($attempt['verified_independently'])
                || ! is_bool($attempt['late'])
                || ! is_bool($attempt['irreversible'])
                || ! is_int($attempt['callback_count'])
                || $attempt['callback_count'] < 0 || $attempt['callback_count'] > 100000
                || ! is_int($attempt['external_cost_minor'])
                || $attempt['external_cost_minor'] < 0 || $attempt['external_cost_minor'] > 1000000000) {
                throw new InvalidArgumentException('Malformed independently reconciled provider attempt.');
            }

            $operation = $attempt['operation_id'];
            $key = $attempt['idempotency_key'];
            if (isset($operations[$operation]) || isset($idempotency[$key])) {
                $flag ??= 'duplicate_provider_attempt_identity';
            }
            $operations[$operation] = true;
            $idempotency[$key] = true;

            if (! $attempt['verified_independently'] || $attempt['outcome'] === 'unknown') {
                $flag ??= 'unverified_provider_attempt';
            } elseif ($attempt['outcome'] === 'confirmed_applied' || $attempt['irreversible']) {
                $flag ??= 'irreversible_provider_attempt_requires_manual_recovery';
            } elseif ($attempt['late'] || $attempt['callback_count'] !== 1) {
                $flag ??= 'late_or_duplicate_provider_callback';
            } elseif ($attempt['external_cost_minor'] > 0) {
                $flag ??= 'provider_cost_requires_independent_settlement';
            }
        }

        // Independent source has to bind the exact immutable ordered attempt
        // list to its evidence manifest; this is an integrity checksum, not
        // external identity proof or authorization to send/rollback.
        $expected = hash('sha256', json_encode([
            'tenant' => $scope->toArray(),
            'run_id' => $runId,
            'snapshot_sha256' => $snapshotSha256,
            'captured_at_unix' => $envelope['captured_at_unix'],
            'expires_at_unix' => $envelope['expires_at_unix'],
            'complete' => $envelope['complete'],
            'attempts' => $envelope['attempts'],
        ], JSON_THROW_ON_ERROR));
        if (! hash_equals($expected, $envelope['source_manifest_sha256'])) {
            throw new InvalidArgumentException('Provider attempt manifest is not bound to original evidence.');
        }

        if (! $envelope['complete'] || $envelope['attempts'] === []) {
            return $this->hold($runId, $snapshotSha256, 'provider_attempt_coverage_incomplete');
        }
        if ($flag !== null) {
            return $this->hold($runId, $snapshotSha256, $flag);
        }

        return [
            'status' => 'offline_no_effect_reconciliation_candidate',
            'reason_code' => 'human_recovery_and_final_authority_required',
            'run_id' => $runId,
            'snapshot_sha256' => $snapshotSha256,
            'source_manifest_sha256' => $envelope['source_manifest_sha256'],
            'verified_attempt_count' => count($envelope['attempts']),
            'rollback_performed' => false,
            'refund_authorized' => false,
            'retry_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private function hold(string $runId, string $snapshotSha256, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => $runId,
            'snapshot_sha256' => $snapshotSha256,
            'source_manifest_sha256' => null,
            'verified_attempt_count' => 0,
            'rollback_performed' => false,
            'refund_authorized' => false,
            'retry_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function keys(array $value, array $required): void
    {
        if (count($value) !== count($required)
            || array_diff(array_keys($value), $required) !== []
            || array_diff($required, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Unregistered provider attempt evidence field.');
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
