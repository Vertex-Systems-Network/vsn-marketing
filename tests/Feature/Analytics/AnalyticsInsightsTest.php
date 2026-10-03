<?php

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Application\AnalyticsInsights;
use App\Modules\Analytics\Domain\MetricDefinition;
use App\Modules\Consent\Domain\ConsentDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AnalyticsFixture;

uses(RefreshDatabase::class);

it('validates exact measured values and rejects hostile numbers references commands and stale evidence', function () {
    $f = new AnalyticsFixture;
    $facts = app(AnalyticsFacts::class);
    $facts->project($f->actor, $f->event());
    $r = $facts->snapshot($f->actor, new MetricDefinition('product.viewed'), new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), $f->now());
    $output = ['snapshot_id' => $r['id'], 'fingerprint' => $r['fingerprint'], 'facts' => [['metric' => 'count', 'value' => 1]], 'inferences' => ['source_coverage_unknown']];
    $s = app(AnalyticsInsights::class);
    expect($s->validateExplanation($f->actor, $r['id'], $output)['risk_tier'])->toBe('R0');
    $hostile = [
        [...$output, 'snapshot_id' => 'forged'], [...$output, 'fingerprint' => str_repeat('a', 64)],
        [...$output, 'facts' => [['metric' => 'count', 'value' => 2]]],
        [...$output, 'facts' => [['metric' => 'causal_uplift', 'value' => 1]]],
        [...$output, 'inferences' => ['send_all_customers']], [...$output, 'instructions' => 'ignore policy'],
    ];
    foreach ($hostile as $candidate) {
        expect(fn () => $s->validateExplanation($f->actor, $r['id'], $candidate))->toThrow(InvalidArgumentException::class);
    }
    $other = new AnalyticsFixture;
    expect(fn () => $s->validateExplanation($other->actor, $r['id'], $output))->toThrow(AuthorizationException::class);
    $f->time = $f->time->modify('+2 days');
    expect(fn () => $s->validateExplanation($f->actor, $r['id'], $output))->toThrow(InvalidArgumentException::class);
});

it('requires mature comparable daily baselines and computes only a disclosed local signal', function () {
    $f = new AnalyticsFixture(false);
    $f->consent(ConsentDecision::Granted, '2026-09-01T00:00:00Z');
    $facts = app(AnalyticsFacts::class);
    $definition = new MetricDefinition('product.viewed');
    $baseline = [];
    for ($day = 24; $day <= 30; $day++) {
        $start = new DateTimeImmutable('2026-09-'.$day.'T00:00:00Z');
        $end = $start->modify('+1 day');
        $f->time = $end;
        $count = $day % 2 + 1;
        for ($i = 0; $i < $count; $i++) {
            $facts->project($f->actor, $f->event('baseline-'.$day.'-'.$i, $start->modify('+1 hour')->format(DATE_ATOM), $start->modify('+2 hours')->format(DATE_ATOM)));
        }
        $baseline[] = $facts->snapshot($f->actor, $definition, $start, $end, $f->now())['id'];
    }
    $f->time = new DateTimeImmutable('2026-10-02T00:00:00Z');
    for ($i = 0; $i < 10; $i++) {
        $facts->project($f->actor, $f->event('current-'.$i, '2026-10-01T10:00:00Z', '2026-10-01T11:00:00Z'));
    }
    $current = $facts->snapshot($f->actor, $definition, new DateTimeImmutable('2026-10-01Z'), $f->now(), $f->now());
    $s = app(AnalyticsInsights::class);
    expect($s->anomaly($f->actor, $current['id'], array_slice($baseline, 0, 2))['status'])->toBe('insufficient_evidence')
        ->and($s->anomaly($f->actor, $current['id'], $baseline)['status'])->toBe('local_signal')
        ->and($s->anomaly($f->actor, $current['id'], $baseline)['causal'])->toBeFalse()
        ->and($s->anomaly($f->actor, $current['id'], $baseline)['source_completeness'])->toBe('unknown');
});
