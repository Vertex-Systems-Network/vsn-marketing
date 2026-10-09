<?php

namespace App\Modules\AI\Application;

use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Experiments\Domain\ExperimentStatistics;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Read-only, independent fixed-horizon canary scoring. This is NOT an
 * autonomous marketing promotion executor or a source of causal proof.
 */
final readonly class BoundedAutonomyOfflineCanaryScore
{
    private const array KEYS = [
        'tenant', 'experiment_id', 'plan_sha256', 'analysis_sha256',
        'source_manifest_sha256', 'assignment_manifest_sha256', 'observed_at_unix', 'expires_at_unix',
        'verified_independent_source', 'assigned', 'exposed', 'outcomes',
        'quarantined', 'crossovers', 'low_trust_count',
    ];

    public function __construct(private BoundedAutonomyCanaryOutcomeSource $source) {}

    public function inspect(
        TenantContext $actor,
        ExperimentPlan $experiment,
        ExperimentAnalysisPlan $analysis,
        DateTimeImmutable $at,
    ): array {
        if ($experiment->workspaceId !== $actor->workspaceId
            || $experiment->brandId !== $actor->brandId
            || $experiment->holdout === null || $experiment->holdout === $experiment->control
            || $experiment->weights !== $analysis->weights
            || $experiment->control !== $analysis->control
            || $experiment->holdout !== $analysis->holdout
            || $experiment->unitKind !== $analysis->unitKind) {
            throw new InvalidArgumentException('Canary analysis mismatches frozen tenant cohort.');
        }

        $facts = $this->source->snapshot($actor, $experiment->id, $at);
        if ($facts === null) {
            return $this->hold($experiment, 'independent_outcome_unavailable');
        }
        self::keys($facts, self::KEYS);
        if ($facts['tenant'] !== $actor->toArray()
            || $facts['experiment_id'] !== $experiment->id
            || $facts['plan_sha256'] !== $experiment->fingerprint()
            || $facts['analysis_sha256'] !== $analysis->fingerprint()
            || ! self::digest($facts['source_manifest_sha256'])
            || ! self::digest($facts['assignment_manifest_sha256'])
            || ! is_int($facts['observed_at_unix'])
            || ! is_int($facts['expires_at_unix'])
            || $facts['observed_at_unix'] > $at->getTimestamp()
            || $facts['observed_at_unix'] < $at->getTimestamp() - 300
            || $facts['expires_at_unix'] <= $at->getTimestamp()
            || $facts['expires_at_unix'] > $at->getTimestamp() + 3600) {
            throw new InvalidArgumentException('Canary independently attested source provenance changed.');
        }
        if ($facts['verified_independent_source'] !== true) {
            return $this->hold($experiment, 'provider_outcome_unverified');
        }

        foreach (['quarantined', 'crossovers', 'low_trust_count'] as $key) {
            if (! is_int($facts[$key]) || $facts[$key] < 0 || $facts[$key] > 1000000) {
                throw new InvalidArgumentException('Canary fraud diagnostic invalid.');
            }
        }
        if ($facts['quarantined'] > 0 || $facts['crossovers'] > 0 || $facts['low_trust_count'] > 0) {
            return $this->hold($experiment, 'spoofed_or_quarantined_event');
        }
        foreach (['assigned', 'exposed', 'outcomes'] as $key) {
            if (! is_array($facts[$key]) || count($facts[$key]) !== count($experiment->weights)) {
                throw new InvalidArgumentException('Canary outcome arm schema invalid.');
            }
            $names = array_keys($facts[$key]);
            $expected = array_keys($experiment->weights);
            sort($names);
            sort($expected);
            if ($names !== $expected) {
                throw new InvalidArgumentException('Unknown canary outcome arm.');
            }
            foreach ($facts[$key] as $v) {
                if (! is_int($v) || $v < 0 || $v > 1000000) {
                    throw new InvalidArgumentException('Invalid independent canary outcome count.');
                }
            }
        }

        // Delegates data-quality, sample-ratio mismatch, fixed horizon,
        // multiplicity and statistical uncertainty to certified experiments.
        $stats = ExperimentStatistics::evaluate(
            $analysis, $facts['assigned'], $facts['exposed'], $facts['outcomes'],
            $facts['quarantined'], $facts['crossovers'], $at,
        );
        // A candidate needs a prespecified practical uplift AND a positive
        // conservative interval for every treatment. A negative-arm signal
        // cannot be used to cherry-pick a favorable treatment for promotion.
        $candidate = $stats['status'] === 'offline_signal' && $stats['effects'] !== [];
        if ($candidate) {
            foreach ($stats['effects'] as $effect) {
                if ($effect['interval'][0] <= 0 || $effect['absolute_difference'] < 0.05) {
                    $candidate = false;
                }
            }
        }

        return [
            'status' => $candidate ? 'offline_operator_review_candidate' : 'held_offline',
            'reason_code' => $candidate ? 'independent_human_promotion_gate_required' : (string) $stats['reason'],
            'experiment_id' => $experiment->id,
            'plan_sha256' => $experiment->fingerprint(),
            'source_manifest_sha256' => $facts['source_manifest_sha256'],
            'assignment_manifest_sha256' => $facts['assignment_manifest_sha256'],
            'assigned_denominator' => array_sum($facts['assigned']),
            'holdout_denominator' => $facts['assigned'][$experiment->holdout],
            'statistical_status' => $stats['status'],
            'promotion_authorized' => false,
            'execution_authorized' => false,
            'external_outcome_proven' => false,
        ];
    }

    private function hold(ExperimentPlan $experiment, string $reason): array
    {
        return [
            'status' => 'held_offline',
            'reason_code' => $reason,
            'experiment_id' => $experiment->id,
            'plan_sha256' => $experiment->fingerprint(),
            'source_manifest_sha256' => null,
            'assignment_manifest_sha256' => null,
            'assigned_denominator' => null,
            'holdout_denominator' => null,
            'statistical_status' => 'not_evaluated',
            'promotion_authorized' => false,
            'execution_authorized' => false,
            'external_outcome_proven' => false,
        ];
    }

    private static function keys(array $input, array $keys): void
    {
        if (count($input) !== count($keys)
            || array_diff(array_keys($input), $keys) !== []
            || array_diff($keys, array_keys($input)) !== []) {
            throw new InvalidArgumentException('Unexpected model-supplied outcome fields.');
        }
    }

    private static function digest(mixed $input): bool
    {
        return is_string($input) && preg_match('/^[a-f0-9]{64}$/D', $input) === 1;
    }
}
