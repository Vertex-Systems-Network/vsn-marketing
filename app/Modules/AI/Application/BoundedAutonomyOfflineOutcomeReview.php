<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyOutcomeSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Offline-only evidence review for irreversible-effect and unknown outcomes.
 * Never retry, re-send, auto-rollback or promote an uncertain provider action.
 */
final readonly class BoundedAutonomyOfflineOutcomeReview
{
    private const array FACT_KEYS = [
        'workspace_id', 'brand_id', 'attempt_id', 'snapshot_sha256',
        'evidence_sha256', 'provider_event_id', 'observed_at_unix', 'state',
    ];

    public function __construct(private BoundedAutonomyOutcomeSource $source) {}

    public function inspect(TenantContext $scope, array $receipt, string $attemptId, DateTimeImmutable $at): array
    {
        if (($receipt['status'] ?? null) !== 'recorded_offline'
            || ($receipt['tenant'] ?? null) !== $scope->toArray()
            || ($receipt['execution_authorized'] ?? null) !== false
            || ($receipt['stages']['execute'] ?? null) !== 'disabled'
            || ! self::id($receipt['run_id'] ?? null)
            || ! self::digest($receipt['snapshot_sha256'] ?? null)
            || ! self::id($attemptId)) {
            throw new InvalidArgumentException('Canonical offline receipt or attempt scope rejected.');
        }

        $facts = $this->source->verifiedOutcome($scope, $attemptId, $at);
        if ($facts === null) {
            return $this->held($receipt, 'independent_outcome_unavailable', false);
        }

        if (count($facts) !== count(self::FACT_KEYS)
            || array_diff(array_keys($facts), self::FACT_KEYS) !== []
            || array_diff(self::FACT_KEYS, array_keys($facts)) !== []
            || $facts['workspace_id'] !== $scope->workspaceId
            || $facts['brand_id'] !== $scope->brandId
            || $facts['attempt_id'] !== $attemptId
            || $facts['snapshot_sha256'] !== $receipt['snapshot_sha256']
            || ! self::digest($facts['evidence_sha256'])
            || ! self::id($facts['provider_event_id'])
            || ! is_int($facts['observed_at_unix'])
            || $facts['observed_at_unix'] > $at->getTimestamp()
            || $facts['observed_at_unix'] < $at->getTimestamp() - 86400
            || ! in_array($facts['state'], [
                'confirmed_no_external_effect',
                'confirmed_irreversible_external_effect',
                'unresolved',
            ], true)) {
            throw new InvalidArgumentException('Untrusted irreversible-outcome evidence rejected.');
        }

        return match ($facts['state']) {
            'confirmed_no_external_effect' => $this->held($receipt, 'fresh_admission_required', true),
            'confirmed_irreversible_external_effect' => $this->held($receipt, 'irreversible_manual_reconciliation_required', false),
            default => $this->held($receipt, 'outcome_unresolved_manual_reconciliation', false),
        };
    }

    private function held(array $receipt, string $reason, bool $verifiedNoEffect): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'run_id' => $receipt['run_id'],
            'snapshot_sha256' => $receipt['snapshot_sha256'],
            'verified_no_effect' => $verifiedNoEffect,
            'execution_authorized' => false,
            'retry_authorized' => false,
            'automatic_rollback_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function id(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
