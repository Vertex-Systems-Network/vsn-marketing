<?php

namespace App\Modules\Experiments\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ExperimentAnalysisPlan
{
    /** @param array<string, int> $weights */
    public function __construct(
        public string $bindingId,
        public string $unitKind,
        public array $weights,
        public string $control,
        public ?string $holdout,
        public float $alpha,
        public float $power,
        public float $baselineRate,
        public float $minimumDetectableDifference,
        public DateTimeImmutable $horizonUtc,
    ) {
        $eligible = array_diff(array_keys($weights), array_filter([$holdout]));
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $bindingId)
            || ! in_array($unitKind, ['contact', 'company'], true)
            || count($weights) < 2 || count($weights) > 16 || array_sum($weights) !== 10000
            || ! isset($weights[$control]) || count($eligible) < 2
            || ($holdout !== null && (! isset($weights[$holdout]) || $holdout === $control))
            || ! in_array($alpha, [0.01, 0.05], true) || ! in_array($power, [0.8, 0.9], true)
            || ! is_finite($baselineRate) || $baselineRate <= 0 || $baselineRate >= 1
            || ! is_finite($minimumDetectableDifference) || $minimumDetectableDifference <= 0
            || $minimumDetectableDifference >= min($baselineRate, 1 - $baselineRate)
            || $horizonUtc->getOffset() !== 0) {
            throw new InvalidArgumentException('Prespecified fixed-horizon analysis plan invalid.');
        }
        foreach ($weights as $name => $weight) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name)
                || ! is_int($weight) || $weight < 1 || $weight > 9999) {
                throw new InvalidArgumentException('Frozen analysis allocation invalid.');
            }
        }
    }

    public function comparisonCount(): int
    {
        return count($this->weights) - 1 - ($this->holdout === null ? 0 : 1);
    }

    public function minimumPerArm(): int
    {
        $zAlpha = ExperimentStatistics::normalQuantile(1 - $this->alpha / (2 * $this->comparisonCount()));
        $zPower = ExperimentStatistics::normalQuantile($this->power);
        $p0 = $this->baselineRate;
        $p1 = min(1, $p0 + $this->minimumDetectableDifference);
        $variance = max($p0 * (1 - $p0), $p1 * (1 - $p1));

        return (int) ceil(2 * ($zAlpha + $zPower) ** 2 * $variance / $this->minimumDetectableDifference ** 2);
    }

    public function canonical(): array
    {
        $weights = $this->weights;
        ksort($weights, SORT_STRING);

        return ['schema_version' => 1, 'binding_id' => $this->bindingId, 'unit_kind' => $this->unitKind,
            'weights' => $weights, 'control' => $this->control, 'holdout' => $this->holdout,
            'primary_outcome' => 'admitted_outcome_event', 'alpha' => $this->alpha, 'power' => $this->power,
            'baseline_rate' => $this->baselineRate, 'minimum_detectable_difference' => $this->minimumDetectableDifference,
            'horizon_utc' => $this->horizonUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'stopping_rule' => 'one_fixed_horizon_look', 'multiplicity' => 'bonferroni',
            'minimum_per_arm' => $this->minimumPerArm()];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->canonical(), JSON_THROW_ON_ERROR));
    }
}
