<?php

use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentStatistics;

function offlinePlan(array $weights = ['control' => 5000, 'treatment' => 5000], ?string $holdout = null): ExperimentAnalysisPlan
{
    return new ExperimentAnalysisPlan('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'contact', $weights, 'control', $holdout,
        0.05, 0.8, 0.5, 0.2, new DateTimeImmutable('2026-10-03T00:00:00+00:00'));
}

it('matches normal, Wilson and chi-square numerical reference values', function () {
    expect(ExperimentStatistics::normalQuantile(0.975))->toBeGreaterThan(1.959)
        ->and(ExperimentStatistics::normalQuantile(0.975))->toBeLessThan(1.961);
    [$low, $high] = ExperimentStatistics::wilson(50, 100, 1.9599639845);
    expect($low)->toBeGreaterThan(0.403)
        ->and($low)->toBeLessThan(0.405)
        ->and($high)->toBeGreaterThan(0.595)
        ->and($high)->toBeLessThan(0.597);
    // Pearson X²=4 with one degree of freedom: survival p≈0.0455003.
    $p = ExperimentStatistics::ratioPValue(['control' => 60, 'treatment' => 40], ['control' => 50, 'treatment' => 50]);
    expect($p)->toBeGreaterThan(0.045)
        ->and($p)->toBeLessThan(0.046);
    // Two degrees of freedom: X²=20/3, survival p=e^(-10/3).
    expect(ExperimentStatistics::ratioPValue(['a' => 40, 'b' => 30, 'c' => 20], ['a' => 30, 'b' => 30, 'c' => 30]))
        ->toBeGreaterThan(0.034)->toBeLessThan(0.036);
});

it('blocks missing exposure, crossover, SRM, quarantine and premature peeking', function () {
    $plan = offlinePlan();
    $future = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
    $final = new DateTimeImmutable('2026-10-04T00:00:00+00:00');
    $base = ['control' => 100, 'treatment' => 100];
    $events = ['control' => 50, 'treatment' => 70];
    expect(ExperimentStatistics::evaluate($plan, $base, $base, $events, 0, 0, $future)['status'])->toBe('pending')
        ->and(ExperimentStatistics::evaluate($plan, $base, ['control' => 99, 'treatment' => 100], $events, 0, 0, $final)['status'])->toBe('invalid')
        ->and(ExperimentStatistics::evaluate($plan, $base, $base, $events, 1, 0, $final)['status'])->toBe('invalid')
        ->and(ExperimentStatistics::evaluate($plan, $base, $base, $events, 0, 1, $final)['status'])->toBe('invalid');
    $srm = ExperimentStatistics::evaluate($plan, ['control' => 150, 'treatment' => 50], ['control' => 150, 'treatment' => 50],
        ['control' => 75, 'treatment' => 35], 0, 0, $final);
    expect($srm['status'])->toBe('invalid')->and($srm['effects'])->toBe([])
        ->and($srm['diagnostics']['assignment_srm_p'])->toBeLessThan(0.001);
});

it('reports guarded offline signal, null interval and short sample without a winner claim', function () {
    $plan = offlinePlan();
    $final = new DateTimeImmutable('2026-10-04T00:00:00+00:00');
    $short = ExperimentStatistics::evaluate($plan, ['control' => 20, 'treatment' => 20], ['control' => 20, 'treatment' => 20],
        ['control' => 10, 'treatment' => 15], 0, 0, $final);
    expect($short['status'])->toBe('inconclusive')->and($short['reason'])->toBe('insufficient_sample');
    $equal = ExperimentStatistics::evaluate($plan, ['control' => 300, 'treatment' => 300], ['control' => 300, 'treatment' => 300],
        ['control' => 150, 'treatment' => 150], 0, 0, $final);
    expect($equal['status'])->toBe('inconclusive')
        ->and($equal['effects']['treatment']['interval'][0])->toBeLessThan(0)
        ->and($equal['effects']['treatment']['interval'][1])->toBeGreaterThan(0);
    $strong = ExperimentStatistics::evaluate($plan, ['control' => 300, 'treatment' => 300], ['control' => 300, 'treatment' => 300],
        ['control' => 60, 'treatment' => 210], 0, 0, $final);
    expect($strong['status'])->toBe('offline_signal')
        ->and($strong['effects']['treatment']['interval'][0])->toBeGreaterThan(0)
        ->and($strong['publication_authorized'])->toBeFalse();
});
