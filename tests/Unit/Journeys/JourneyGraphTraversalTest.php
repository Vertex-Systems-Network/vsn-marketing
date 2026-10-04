<?php

use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyGraphTraversal;

function journeyTraversalGraph(): array
{
    return [
        'schema_version' => 1,
        'nodes' => [
            ['id' => 'trigger', 'type' => 'trigger', 'config' => ['event' => 'customer.created']],
            ['id' => 'wait', 'type' => 'wait', 'config' => ['seconds' => 2]],
            ['id' => 'branch', 'type' => 'branch', 'config' => ['field' => 'tier', 'operator' => 'equals', 'value' => 'gold']],
            ['id' => 'action', 'type' => 'action', 'config' => ['capability' => 'email.send']],
            ['id' => 'exit', 'type' => 'exit', 'config' => ['event' => 'customer.left']],
            ['id' => 'end', 'type' => 'end'],
        ],
        'edges' => [
            ['from' => 'trigger', 'to' => 'wait'],
            ['from' => 'wait', 'to' => 'branch'],
            ['from' => 'branch', 'to' => 'action', 'type' => 'true'],
            ['from' => 'branch', 'to' => 'exit', 'type' => 'false'],
            ['from' => 'action', 'to' => 'end'],
        ],
    ];
}

it('resolves a unique trigger and each typed route of a bounded graph', function () {
    $traversal = new JourneyGraphTraversal;
    $graph = journeyTraversalGraph();

    expect($traversal->entry($graph, 'customer.created'))->toBe('trigger')
        ->and($traversal->successors($graph, 'trigger'))->toBe(['wait'])
        ->and($traversal->successors($graph, 'wait'))->toBe(['branch'])
        ->and($traversal->successors($graph, 'branch', ['tier' => 'gold']))->toBe(['action'])
        ->and($traversal->successors($graph, 'branch', ['tier' => 'bronze']))->toBe(['exit'])
        ->and($traversal->successors($graph, 'action'))->toBe(['end'])
        ->and($traversal->successors($graph, 'end'))->toBe([]);
});

it('fails closed on a missing trigger or ambiguous branch before running an action', function () {
    $traversal = new JourneyGraphTraversal;
    $graph = journeyTraversalGraph();
    expect(fn () => $traversal->entry($graph, 'customer.deleted'))->toThrow(JourneyDefinitionException::class);

    $graph['edges'][3]['type'] = 'true';
    expect(fn () => $traversal->successors($graph, 'branch', ['tier' => 'gold']))->toThrow(JourneyDefinitionException::class);
});
