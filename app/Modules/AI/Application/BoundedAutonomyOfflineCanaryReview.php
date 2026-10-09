<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryCohortSource;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Experiments\Domain\ExperimentStatistics;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase-15 OFFLINE canary/holdout operator evidence only.
 *
 * The existing Experiments module owns persisted assignments and eligibility;
 * this class never changes cohorts, enrolls recipients or promotes a strategy.
 */
final readonly class BoundedAutonomyOfflineCanaryReview
{
    private const array REQUIRED = [
        'tenant', 'plan_sha256', 'assignment_manifest_sha256',
        'observed_at_unix', 'expires_at_unix', 'frozen', 'consent_verified',
        'quarantined', 'crossovers', 'counts',
    ];

    public function __construct(private BoundedAutonomyCanaryCohortSource $source) {}

    public function inspect(TenantContext $actor, ExperimentPlan $plan, DateTimeImmutable $at): array
    {
        if ($plan->workspaceId !== $actor->workspaceId || $plan->brandId !== $actor->brandId) {
            throw new InvalidArgumentException('Canary review cannot cross workspace or brand.');
        }
        if ($plan->holdout === null || $plan->holdout === $plan->control) {
            throw new InvalidArgumentException('Canary requires an immutable independent holdout.');
        }

        $facts = $this->source->snapshot($actor, $plan->id, $at);
        if ($facts === null) {
            return $this->held($plan, 'independent_cohort_unavailable');
        }
        self::keys($facts, self::REQUIRED);
        if (($facts['tenant'] ?? null) !== $actor->toArray()
            || $facts['plan_sha256'] !== $plan->fingerprint()
            || ! self::digest($facts['assignment_manifest_sha256'])
            || ! is_int($facts['observed_at_unix'])
            || ! is_int($facts['expires_at_unix'])
            || $facts['observed_at_unix'] > $at->getTimestamp()
            || $facts['observed_at_unix'] < $at->getTimestamp() - 300
            || $facts['expires_at_unix'] <= $at->getTimestamp()
            || $facts['expires_at_unix'] > $at->getTimestamp() + 3600) {
            throw new InvalidArgumentException('Untrusted frozen cohort provenance.');
        }
        if ($facts['frozen'] !== true || $facts['consent_verified'] !== true) {
            return $this->held($plan, 'cohort_not_frozen_or_consent_unverified');
        }
        if (! is_int($facts['quarantined']) || $facts['quarantined'] < 0
            || ! is_int($facts['crossovers']) || $facts['crossovers'] < 0) {
            throw new InvalidArgumentException('Invalid independent cohort diagnostics.');
        }
        if ($facts['quarantined'] > 0 || $facts['crossovers'] > 0) {
            return $this->held($plan, 'cohort_quality_quarantined');
        }

        $names = array_keys($plan->weights);
        sort($names);
        if (! is_array($facts['counts'])) {
            throw new InvalidArgumentException('Invalid cohort denominator schema.');
        }
        $received = array_keys($facts['counts']);
        sort($received);
        if ($received !== $names) {
            throw new InvalidArgumentException('Unknown or missing canary cohort variant.');
        }

        $assigned = [];
        foreach ($facts['counts'] as $variant => $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Invalid canary cohort metric row.');
            }
            self::keys($row, ['eligible', 'assigned', 'exposed']);
            foreach ($row as $amount) {
                if (! is_int($amount) || $amount < 0 || $amount > 1000000) {
                    throw new InvalidArgumentException('Invalid canary cohort denominator.');
                }
            }
            if ($row['assigned'] > $row['eligible'] || $row['exposed'] > $row['assigned']
                || ($variant === $plan->holdout && $row['exposed'] !== 0)) {
                return $this->held($plan, 'exposure_or_consent_denominator_invalid');
            }
            if ($row['assigned'] < 50) {
                return $this->held($plan, 'insufficient_independent_cohort');
            }
            $assigned[$variant] = $row['assigned'];
        }

        ksort($assigned);
        $weights = $plan->weights;
        ksort($weights);
        $expected = array_sum($assigned);
        foreach ($weights as $weight) {
            if ($expected * $weight / 10000 < 5) {
                return $this->held($plan, 'insufficient_assignment_srm_support');
            }
        }
        if (ExperimentStatistics::ratioPValue($assigned, $weights) < 0.001) {
            return $this->held($plan, 'assignment_sample_ratio_mismatch');
        }

        return [
            'status' => 'offline_cohort_review_ready',
            'reason_code' => 'independent_outcome_and_promotion_gates_required',
            'experiment_id' => $plan->id,
            'plan_sha256' => $plan->fingerprint(),
            'assignment_manifest_sha256' => $facts['assignment_manifest_sha256'],
            'assigned_denominator' => array_sum($assigned),
            'holdout_denominator' => $assigned[$plan->holdout],
            'exposure_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private function held(ExperimentPlan $plan, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'experiment_id' => $plan->id,
            'plan_sha256' => $plan->fingerprint(),
            'assignment_manifest_sha256' => null,
            'assigned_denominator' => null,
            'holdout_denominator' => null,
            'exposure_authorized' => false,
            'execution_authorized' => false,
            'promotion_authorized' => false,
        ];
    }

    private static function keys(array $value, array $keys): void
    {
        if (count($value) !== count($keys)
            || array_diff(array_keys($value), $keys) !== []
            || array_diff($keys, array_keys($value)) !== []) {
            throw new InvalidArgumentException('Unregistered canary evidence schema.');
        }
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
