<?php

use App\Modules\Analytics\Domain\BehaviorDefinition;
use App\Modules\Analytics\Domain\BehaviorMetrics;

function behaviorObservation(string $id, string $subject, string $type, string $at, ?string $dimension = null): array
{
    return ['event_id' => $id, 'subject_key' => $subject, 'event_type' => $type, 'occurred_at' => $at, 'dimension' => $dimension];
}

it('uses first eligible start, deterministic ties and a bounded ordered conversion horizon', function () {
    $d = new BehaviorDefinition('funnel', ['product.viewed', 'cart.created', 'order.completed'], conversionSeconds: 3600);
    $facts = [
        behaviorObservation('a', 'one', 'product.viewed', '2026-10-01T10:00:00Z'),
        behaviorObservation('b', 'one', 'cart.created', '2026-10-01T10:00:00Z'),
        behaviorObservation('c', 'one', 'order.completed', '2026-10-01T11:00:00Z'),
        behaviorObservation('d', 'two', 'cart.created', '2026-10-01T09:00:00Z'),
        behaviorObservation('e', 'two', 'product.viewed', '2026-10-01T10:00:00Z'),
        behaviorObservation('f', 'two', 'product.viewed', '2026-10-01T12:00:00Z'),
        behaviorObservation('g', 'two', 'cart.created', '2026-10-01T12:01:00Z'),
    ];
    $s = new DateTimeImmutable('2026-10-01T00:00:00Z');
    $e = new DateTimeImmutable('2026-10-02T00:00:00Z');
    $result = (new BehaviorMetrics)->calculate($d, $facts, $s, $e, $e);
    expect(array_column($result['steps'], 'subjects'))->toBe([2, 1, 1])
        ->and(array_column($result['steps'], 'denominator'))->toBe([2, 2, 1])
        ->and($result['conversion_rate'])->toBe(0.5)
        ->and((new BehaviorMetrics)->calculate($d, array_reverse($facts), $s, $e, $e))->toBe($result)
        ->and($result['tie_quality'])->toBe('deterministic_not_causal');
});

it('censors immature retention bins and counts a returning subject once per mature bin', function () {
    $d = new BehaviorDefinition('retention', ['contact.created', 'product.viewed'], bins: 3);
    $facts = [
        behaviorObservation('a', 'old', 'contact.created', '2026-10-01T00:00:00Z'),
        behaviorObservation('b', 'old', 'product.viewed', '2026-10-01T01:00:00Z'),
        behaviorObservation('c', 'old', 'product.viewed', '2026-10-01T02:00:00Z'),
        behaviorObservation('d', 'old', 'product.viewed', '2026-10-02T00:00:00Z'),
        behaviorObservation('e', 'recent', 'contact.created', '2026-10-01T23:00:00Z'),
        behaviorObservation('f', 'recent', 'product.viewed', '2026-10-02T01:00:00Z'),
    ];
    $r = (new BehaviorMetrics)->calculate($d, $facts, new DateTimeImmutable('2026-10-01Z'),
        new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'));
    expect($r['cohort_size'])->toBe(2)
        ->and(array_column($r['bins'], 'eligible_subjects'))->toBe([2, 1, 0])
        ->and(array_column($r['bins'], 'retained_subjects'))->toBe([2, 1, null])
        ->and(array_column($r['bins'], 'censored_subjects'))->toBe([0, 1, 2])
        ->and(array_column($r['bins'], 'rate'))->toBe([1, 1, null]);
});

it('separates lifecycle intervals without inventing lifetime acquisition', function () {
    $facts = [behaviorObservation('a', 'both', 'product.viewed', '2026-10-01T10:00:00Z'),
        behaviorObservation('b', 'both', 'product.viewed', '2026-10-02T10:00:00Z'),
        behaviorObservation('c', 'old', 'product.viewed', '2026-10-01T10:00:00Z'),
        behaviorObservation('d', 'current', 'product.viewed', '2026-10-02T10:00:00Z')];
    $r = (new BehaviorMetrics)->calculate(new BehaviorDefinition('lifecycle', ['product.viewed']), $facts,
        new DateTimeImmutable('2026-10-02Z'), new DateTimeImmutable('2026-10-03Z'), new DateTimeImmutable('2026-10-03Z'));
    expect($r['categories'])->toBe(['continuing' => 1, 'observed_current_only' => 1, 'dormant' => 1])
        ->and($r['current_active'])->toBe(2)->and($r['previous_active'])->toBe(2)
        ->and($r['prior_history_coverage'])->toBe('unknown')->and($r['current_only_is_proven_new_acquisition'])->toBeFalse();
});

it('discloses empty denominators, partial funnel horizons and unsupported definitions', function () {
    $d = new BehaviorDefinition('funnel', ['product.viewed', 'cart.created']);
    $start = new DateTimeImmutable('2026-10-01Z');
    $end = new DateTimeImmutable('2026-10-02Z');
    expect((new BehaviorMetrics)->calculate($d, [], $start, $end, $end)['conversion_rate'])->toBeNull();
    $f = [behaviorObservation('a', 'one', 'product.viewed', '2026-10-01T23:00:00Z')];
    expect((new BehaviorMetrics)->calculate($d, $f, $start, $end, $end)['incomplete_horizon_subjects'])->toBe(1)
        ->and(fn () => (new BehaviorMetrics)->calculate($d, [...$f, ...$f], $start, $end, $end))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BehaviorDefinition('any_order', ['product.viewed', 'cart.created']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BehaviorDefinition('retention', ['product.viewed'], bins: 100))->toThrow(InvalidArgumentException::class);
});
