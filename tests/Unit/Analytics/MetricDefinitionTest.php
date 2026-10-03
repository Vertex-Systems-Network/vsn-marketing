<?php

use App\Modules\Analytics\Domain\MetricDefinition;

it('binds definition fingerprints to supported unit and version semantics', function () {
    $events = new MetricDefinition('product.viewed');
    expect($events->fingerprint())->toBe((new MetricDefinition('product.viewed'))->fingerprint())
        ->and($events->fingerprint())->not->toBe((new MetricDefinition('product.viewed', 'subject'))->fingerprint())
        ->and($events->toArray()['causal'])->toBeFalse()
        ->and((new MetricDefinition('product.viewed', 'subject'))->toArray()['semantics'])->toBe('admitted_scoped_subject_count')
        ->and(fn () => new MetricDefinition('unknown.action'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MetricDefinition('product.viewed', 'person'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MetricDefinition('product.viewed', 'event', 2))->toThrow(InvalidArgumentException::class);
});
