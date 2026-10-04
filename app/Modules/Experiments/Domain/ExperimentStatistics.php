<?php

namespace App\Modules\Experiments\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/** Fixed-horizon offline binary admitted-event analysis; no production metric verifier. */
final class ExperimentStatistics
{
    public static function normalQuantile(float $probability): float
    {
        if ($probability <= 0 || $probability >= 1 || ! is_finite($probability)) {
            throw new InvalidArgumentException('Normal quantile probability invalid.');
        }
        $low = -10.0;
        $high = 10.0;
        for ($i = 0; $i < 70; $i++) {
            $mid = ($low + $high) / 2;
            if (self::normalCdf($mid) < $probability) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return ($low + $high) / 2;
    }

    public static function normalCdf(float $z): float
    {
        return 0.5 * self::erfcApprox(-$z / sqrt(2));
    }

    /** Pearson chi-square survival; half-integer incomplete-gamma recurrence for 1..15 df. */
    public static function ratioPValue(array $counts, array $weights): float
    {
        if (array_keys($counts) !== array_keys($weights) || count($counts) < 2 || count($counts) > 16) {
            throw new InvalidArgumentException('SRM variant keys mismatch.');
        }
        $total = array_sum($counts);
        $weightTotal = array_sum($weights);
        if ($total <= 0 || $weightTotal <= 0) {
            throw new InvalidArgumentException('SRM has no samples or allocation.');
        }
        $statistic = 0.0;
        foreach ($counts as $name => $observed) {
            if (! is_int($observed) || $observed < 0 || ! is_int($weights[$name]) || $weights[$name] <= 0) {
                throw new InvalidArgumentException('SRM counts or weights invalid.');
            }
            $expected = $total * $weights[$name] / $weightTotal;
            if ($expected < 5) {
                throw new InvalidArgumentException('SRM expected cell below chi-square threshold.');
            }
            $statistic += ($observed - $expected) ** 2 / $expected;
        }
        $x = $statistic / 2;
        if ($x === 0.0) {
            return 1.0;
        }
        $degrees = count($counts) - 1;
        if ($degrees % 2 === 0) {
            $shape = 1.0;
            $gamma = 1.0;
            $q = exp(-$x);
        } else {
            $shape = 0.5;
            $gamma = sqrt(M_PI);
            $q = self::erfcApprox(sqrt($x));
        }
        $target = $degrees / 2;
        while ($shape < $target) {
            $q += exp(-$x) * pow($x, $shape) / ($shape * $gamma);
            $gamma *= $shape;
            $shape++;
        }

        return min(1.0, max(0.0, $q));
    }

    /** @return array{0:float,1:float} Wilson binomial proportion interval. */
    public static function wilson(int $events, int $total, float $z): array
    {
        if ($total < 1 || $events < 0 || $events > $total || $z <= 0 || ! is_finite($z)) {
            throw new InvalidArgumentException('Wilson interval inputs invalid.');
        }
        $p = $events / $total;
        $denominator = 1 + $z ** 2 / $total;
        $center = ($p + $z ** 2 / (2 * $total)) / $denominator;
        $half = $z * sqrt($p * (1 - $p) / $total + $z ** 2 / (4 * $total ** 2)) / $denominator;

        return [max(0.0, $center - $half), min(1.0, $center + $half)];
    }

    /** @param array<string,int> $assigned @param array<string,int> $exposed @param array<string,int> $outcomes */
    public static function evaluate(ExperimentAnalysisPlan $plan, array $assigned, array $exposed, array $outcomes,
        int $quarantined, int $crossovers, DateTimeImmutable $at): array
    {
        $names = array_keys($plan->weights);
        sort($names);
        foreach ([$assigned, $exposed, $outcomes] as $counts) {
            $keys = array_keys($counts);
            sort($keys);
            if ($keys !== $names) {
                throw new InvalidArgumentException('Analysis arm keys mismatch.');
            }
            foreach ($counts as $count) {
                if (! is_int($count) || $count < 0) {
                    throw new InvalidArgumentException('Analysis count invalid.');
                }
            }
        }
        if ($quarantined < 0 || $crossovers < 0) {
            throw new InvalidArgumentException('Analysis diagnostic count invalid.');
        }
        $weights = $plan->weights;
        ksort($weights);
        $assigned = array_replace($weights, $assigned);
        $exposed = array_replace($weights, $exposed);
        $outcomes = array_replace($weights, $outcomes);
        $diagnostics = ['assigned' => $assigned, 'exposed' => $exposed, 'outcomes' => $outcomes,
            'quarantined' => $quarantined, 'crossovers' => $crossovers, 'minimum_per_arm' => $plan->minimumPerArm(),
            'assignment_srm_p' => null, 'exposure_srm_p' => null, 'missing_exposure' => 0];
        $treatments = array_diff($names, array_filter([$plan->holdout]));
        foreach ($names as $name) {
            if ($exposed[$name] > $assigned[$name] || $outcomes[$name] > $exposed[$name]
                || ($name === $plan->holdout && ($exposed[$name] !== 0 || $outcomes[$name] !== 0))) {
                return ['status' => 'invalid', 'reason' => 'count_integrity', 'diagnostics' => $diagnostics, 'effects' => []];
            }
            if ($name !== $plan->holdout) {
                $diagnostics['missing_exposure'] += $assigned[$name] - $exposed[$name];
            }
        }
        if (array_sum($assigned) >= 5 * count($names) && min(array_map(
            fn (string $name): float => array_sum($assigned) * $weights[$name] / 10000, $names,
        )) >= 5) {
            $diagnostics['assignment_srm_p'] = self::ratioPValue($assigned, $weights);
        }
        $exposureWeights = array_intersect_key($weights, array_fill_keys($treatments, true));
        $exposureCounts = array_intersect_key($exposed, $exposureWeights);
        $exposureTotal = array_sum($exposureCounts);
        $exposureWeightTotal = array_sum($exposureWeights);
        if ($exposureTotal > 0 && min(array_map(
            fn (int $weight): float => $exposureTotal * $weight / $exposureWeightTotal, $exposureWeights,
        )) >= 5) {
            $diagnostics['exposure_srm_p'] = self::ratioPValue($exposureCounts, $exposureWeights);
        }
        if ($quarantined > 0 || $crossovers > 0 || $diagnostics['missing_exposure'] > 0
            || ($diagnostics['assignment_srm_p'] !== null && $diagnostics['assignment_srm_p'] < 0.001)
            || ($diagnostics['exposure_srm_p'] !== null && $diagnostics['exposure_srm_p'] < 0.001)) {
            return ['status' => 'invalid', 'reason' => 'data_quality', 'diagnostics' => $diagnostics, 'effects' => []];
        }
        if ($at < $plan->horizonUtc) {
            return ['status' => 'pending', 'reason' => 'fixed_horizon', 'diagnostics' => $diagnostics, 'effects' => []];
        }
        foreach ($treatments as $name) {
            if ($assigned[$name] < $plan->minimumPerArm()) {
                return ['status' => 'inconclusive', 'reason' => 'insufficient_sample', 'diagnostics' => $diagnostics, 'effects' => []];
            }
        }
        $z = self::normalQuantile(1 - $plan->alpha / (4 * $plan->comparisonCount()));
        [$controlLower, $controlUpper] = self::wilson($outcomes[$plan->control], $assigned[$plan->control], $z);
        $effects = [];
        $signal = false;
        foreach ($treatments as $name) {
            if ($name === $plan->control) {
                continue;
            }
            [$lower, $upper] = self::wilson($outcomes[$name], $assigned[$name], $z);
            $interval = [$lower - $controlUpper, $upper - $controlLower];
            $effects[$name] = ['absolute_difference' => $outcomes[$name] / $assigned[$name] - $outcomes[$plan->control] / $assigned[$plan->control],
                'interval' => $interval, 'comparison_alpha' => $plan->alpha / $plan->comparisonCount()];
            $signal = $signal || $interval[0] > 0 || $interval[1] < 0;
        }

        return ['status' => $signal ? 'offline_signal' : 'inconclusive', 'reason' => $signal ? 'interval_excludes_zero' : 'interval_includes_zero',
            'diagnostics' => $diagnostics, 'effects' => $effects, 'publication_authorized' => false];
    }

    /** Abramowitz-Stegun 7.1.26; maximum absolute error approximately 1.5e-7. */
    private static function erfcApprox(float $x): float
    {
        $t = 1 / (1 + 0.3275911 * abs($x));
        $poly = (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t;
        $erfc = $poly * exp(-$x * $x);

        return $x >= 0 ? $erfc : 2 - $erfc;
    }
}
