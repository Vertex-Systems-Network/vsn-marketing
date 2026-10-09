<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyExpectedProviderOperationsSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Require exact canonical planned operation inventory in addition to both
 * independent provider aggregate and per-attempt non-effect attestations.
 * A provider's self-declared "complete" list is insufficient.
 *
 * This method has zero external side effects and never authorizes rollback.
 */
final readonly class BoundedAutonomyOfflineOperationCoverageReview
{
    private const array FIELDS = [
        'tenant', 'run_id', 'snapshot_sha256', 'observed_at_unix',
        'expires_at_unix', 'complete', 'operations', 'operation_set_sha256',
    ];

    private const array OPERATION_FIELDS = ['operation_id', 'idempotency_key'];

    public function __construct(
        private BoundedAutonomyOfflineJointRollbackReview $joint,
        private BoundedAutonomyExpectedProviderOperationsSource $expected,
    ) {}

    public function inspect(
        TenantContext $scope,
        string $runId,
        string $snapshotSha256,
        DateTimeImmutable $at,
    ): array {
        $review = $this->joint->inspect($scope, $runId, $snapshotSha256, $at);
        if (($review['status'] ?? null) !== 'offline_joint_rollback_review_ready') {
            return $this->held($runId, $snapshotSha256, 'joint_provider_outcome_unresolved');
        }
        foreach (['rollback_performed', 'refund_authorized', 'retry_authorized', 'execution_authorized', 'promotion_authorized'] as $field) {
            if (($review[$field] ?? null) !== false) {
                throw new InvalidArgumentException('Provider review tried to grant forbidden external authority.');
            }
        }

        $inventory = $this->expected->latest($scope, $runId, $at);
        if ($inventory === null) {
            return $this->held($runId, $snapshotSha256, 'canonical_operation_inventory_unavailable');
        }

        self::requireKeys($inventory, self::FIELDS);
        if (($inventory['tenant'] ?? null) !== $scope->toArray()
            || ($inventory['run_id'] ?? null) !== $runId
            || ($inventory['snapshot_sha256'] ?? null) !== $snapshotSha256
            || ! is_int($inventory['observed_at_unix'])
            || ! is_int($inventory['expires_at_unix'])
            || $inventory['observed_at_unix'] > $at->getTimestamp()
            || $inventory['observed_at_unix'] < $at->getTimestamp() - 3600
            || $inventory['expires_at_unix'] <= $at->getTimestamp()
            || $inventory['expires_at_unix'] > $at->getTimestamp() + 3600
            || ! is_bool($inventory['complete'])
            || ! is_array($inventory['operations'])
            || ! array_is_list($inventory['operations'])
            || count($inventory['operations']) > 128
            || ! self::digest($inventory['operation_set_sha256'])) {
            throw new InvalidArgumentException('Canonical operation inventory provenance rejected.');
        }

        $identities = [];
        $ids = [];
        $keys = [];
        foreach ($inventory['operations'] as $operation) {
            if (! is_array($operation)) {
                throw new InvalidArgumentException('Non-object canonical operation entry.');
            }
            self::requireKeys($operation, self::OPERATION_FIELDS);
            $id = $operation['operation_id'];
            $key = $operation['idempotency_key'];
            if (! self::identifier($id) || ! self::identifier($key)) {
                throw new InvalidArgumentException('Malformed canonical operation identity.');
            }
            if (isset($ids[$id]) || isset($keys[$key])) {
                return $this->held($runId, $snapshotSha256, 'duplicate_canonical_operation_identity');
            }
            $ids[$id] = true;
            $keys[$key] = true;
            $identities[] = ['operation_id' => $id, 'idempotency_key' => $key];
        }

        usort($identities, static fn (array $left, array $right): int => [$left['operation_id'], $left['idempotency_key']] <=> [$right['operation_id'], $right['idempotency_key']]);
        $digest = hash('sha256', json_encode($identities, JSON_THROW_ON_ERROR));
        if (! hash_equals($inventory['operation_set_sha256'], $digest)) {
            throw new InvalidArgumentException('Canonical operation inventory checksum mismatch.');
        }
        if (! $inventory['complete'] || $identities === []) {
            return $this->held($runId, $snapshotSha256, 'canonical_operation_inventory_incomplete');
        }

        // Equal cardinality alone is insecure: ensure the EXACT same ordered
        // set of operation + idempotency keys was independently accounted for.
        if (($review['verified_attempt_count'] ?? null) !== count($identities)
            || ! self::digest($review['operation_set_sha256'] ?? null)
            || ! hash_equals($digest, $review['operation_set_sha256'])) {
            return $this->held($runId, $snapshotSha256, 'provider_attempt_inventory_mismatch');
        }

        return [
            'status' => 'offline_complete_non_effect_review_candidate',
            'reason_code' => 'human_recovery_authority_still_required',
            'run_id' => $runId,
            'snapshot_sha256' => $snapshotSha256,
            'operation_set_sha256' => $digest,
            'verified_attempt_count' => count($identities),
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
            'operation_set_sha256' => null,
            'verified_attempt_count' => 0,
            'rollback_performed' => false,
            'refund_authorized' => false,
            'retry_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function requireKeys(array $value, array $schema): void
    {
        if (count($value) !== count($schema)
            || array_diff(array_keys($value), $schema) !== []
            || array_diff($schema, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Unexpected canonical operation inventory field.');
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
