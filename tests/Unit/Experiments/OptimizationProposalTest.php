<?php

use App\Modules\Experiments\Domain\CampaignExperimentMatrix;
use App\Modules\Experiments\Domain\OptimizationProposal;

it('rejects malformed provenance and budget while canonicalizing source order', function () {
    $matrix = new CampaignExperimentMatrix(['control' => [
        'content' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'time' => '2026-10-03T09:00:00Z',
        'audience' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
    ]]);
    $args = ['trace-1', str_repeat('a', 64), str_repeat('b', 64), ['source:b', 'source:a'], $matrix, 'medium', 'low', 100, 30];
    $first = new OptimizationProposal(...$args);
    $args[3] = ['source:a', 'source:b'];
    expect($first->fingerprint('workspace', 'binding', 'matrix'))
        ->toBe((new OptimizationProposal(...$args))->fingerprint('workspace', 'binding', 'matrix'));
    $args[8] = 101;
    expect(fn () => new OptimizationProposal(...$args))->toThrow(InvalidArgumentException::class);
    $args[8] = 30;
    $args[3] = ['source:a', 'source:a'];
    expect(fn () => new OptimizationProposal(...$args))->toThrow(InvalidArgumentException::class);
});
