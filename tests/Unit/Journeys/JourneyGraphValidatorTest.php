<?php

use App\Modules\Journeys\Application\JourneyEnrollmentGuard;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyExecutionIdentity;
use App\Modules\Journeys\Domain\JourneyExecutionState;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use App\Modules\Journeys\Domain\JourneyReentryPolicy;
use App\Modules\Journeys\Domain\JourneyRuntimePolicy;

function journeyGraph(): array
{
    return [
        'schema_version' => 1,
        'nodes' => [
            ['id' => 'start', 'type' => 'trigger', 'config' => ['event' => 'customer.created']],
            ['id' => 'wait', 'type' => 'wait', 'config' => ['seconds' => 60]],
            ['id' => 'end', 'type' => 'end'],
        ],
        'edges' => [['from' => 'start', 'to' => 'wait'], ['from' => 'wait', 'to' => 'end']],
    ];
}

it('canonicalizes equivalent graphs and produces a stable hash', function () {
    $v = new JourneyGraphValidator;
    $a = journeyGraph();
    $b = $a;
    $b['nodes'] = array_reverse($b['nodes']);
    $b['edges'] = array_reverse($b['edges']);
    expect($v->hash($a))->toBe($v->hash($b));
});

it('rejects unknown nodes, executable text, and foreign edges', function () {
    $v = new JourneyGraphValidator;
    expect(fn () => $v->normalize(['schema_version' => 1, 'nodes' => [['id' => 'x', 'type' => 'sql', 'sql' => 'select 1']]]))->toThrow(JourneyDefinitionException::class);
    expect(fn () => $v->normalize(['schema_version' => 1, 'nodes' => [['id' => 'x', 'type' => 'action', 'code' => 'exec()']]]))->toThrow(JourneyDefinitionException::class);
    expect(fn () => $v->normalize(['schema_version' => 1, 'nodes' => [['id' => 'x', 'type' => 'end']], 'edges' => [['from' => 'x', 'to' => 'foreign']]]))->toThrow(JourneyDefinitionException::class);
});

it('keeps execution and node-attempt identities deterministic and scoped', function () {
    $execution = JourneyExecutionIdentity::for('w1', 'v1', 's1', 'e1');
    expect($execution)->toBe(JourneyExecutionIdentity::for('w1', 'v1', 's1', 'e1'))
        ->not->toBe(JourneyExecutionIdentity::for('w2', 'v1', 's1', 'e1'))
        ->not->toBe(JourneyExecutionIdentity::nodeAttempt($execution, 'node', 1));
});

it('bounds durable waits and produces UTC deadlines', function () {
    $policy = new JourneyRuntimePolicy(maxWaitSeconds: 3600);
    expect($policy->deadline(new DateTimeImmutable('2026-09-27 00:00:00', new DateTimeZone('UTC')), 60)->format('Y-m-d H:i:s'))->toBe('2026-09-27 00:01:00');
    expect(fn () => $policy->assertWait(3601))->toThrow(JourneyDefinitionException::class);
});

it('allows only durable execution state transitions', function () {
    $state = new JourneyExecutionState('w1', 'e1');
    $state->transition('running');
    $state->transition('waiting');
    $state->transition('running');
    $state->transition('succeeded');
    expect($state->status)->toBe('succeeded')->and($state->revision)->toBe(4);
    expect(fn () => $state->transition('running'))->toThrow(JourneyDefinitionException::class);
});

it('rejects unknown graph node and edge fields instead of accepting executable extensions', function () {
    $v = new JourneyGraphValidator;
    $graph = journeyGraph();
    $graph['unexpected'] = 'value';
    expect(fn () => $v->normalize($graph))->toThrow(JourneyDefinitionException::class);

    $graph = journeyGraph();
    $graph['nodes'][0]['handler'] = 'exec';
    expect(fn () => $v->normalize($graph))->toThrow(JourneyDefinitionException::class);

    $graph = journeyGraph();
    $graph['edges'][0]['script'] = 'run';
    expect(fn () => $v->normalize($graph))->toThrow(JourneyDefinitionException::class);

    $graph = journeyGraph();
    $graph['edges'][0]['type'] = 'execute arbitrary callback';
    expect(fn () => $v->normalize($graph))->toThrow(JourneyDefinitionException::class);
});

it('rejects malformed, duplicate, cyclic, and over-depth edges', function () {
    $v = new JourneyGraphValidator;

    $graph = journeyGraph();
    $graph['edges'] = 'invalid';
    expect(fn () => $v->normalize($graph))->toThrow(JourneyDefinitionException::class);

    $graph = journeyGraph();
    $graph['edges'][] = $graph['edges'][0];
    expect(fn () => $v->normalize($graph))->toThrow(JourneyDefinitionException::class);

    $graph = journeyGraph();
    $graph['edges'][] = ['from' => 'end', 'to' => 'start'];
    expect(fn () => $v->normalize($graph))->toThrow(JourneyDefinitionException::class);

    $graph = [
        'schema_version' => 1,
        'nodes' => array_map(fn (int $i): array => ['id' => 'n'.$i, 'type' => 'action', 'config' => ['capability' => 'mail.send']], range(1, JourneyGraphValidator::MAX_DEPTH + 1)),
        'edges' => array_map(fn (int $i): array => ['from' => 'n'.$i, 'to' => 'n'.($i + 1)], range(1, JourneyGraphValidator::MAX_DEPTH)),
    ];
    expect(fn () => $v->normalize($graph))->toThrow(JourneyDefinitionException::class);
});

it('canonicalizes nested configuration map keys before hashing', function () {
    $v = new JourneyGraphValidator;
    $a = ['schema_version' => 1, 'nodes' => [['id' => 'x', 'type' => 'action', 'config' => ['capability' => 'mail.send', 'input' => ['y' => true, 'b' => false]]]]];
    $b = ['schema_version' => 1, 'nodes' => [['id' => 'x', 'type' => 'action', 'config' => ['input' => ['b' => false, 'y' => true], 'capability' => 'mail.send']]]];
    expect($v->hash($a))->toBe($v->hash($b));
});

it('uses unambiguous tuple identities and rejects noncanonical event identifiers', function () {
    expect(JourneyExecutionIdentity::for('w|v', 'x', 's', 'e'))
        ->not->toBe(JourneyExecutionIdentity::for('w', 'v|x', 's', 'e'));

    $guard = new JourneyEnrollmentGuard;
    expect(fn () => $guard->key('w1', 'v1', 's1', ['event_id' => 123]))->toThrow(InvalidArgumentException::class);
});

it('validates each registered node configuration against its typed schema', function () {
    $validator = new JourneyGraphValidator;
    $graph = ['schema_version' => 1, 'nodes' => [
        ['id' => 'trigger', 'type' => 'trigger', 'config' => ['event' => 'customer.created']],
        ['id' => 'wait', 'type' => 'wait', 'config' => ['seconds' => 10]],
        ['id' => 'end', 'type' => 'end'],
    ], 'edges' => [['from' => 'trigger', 'to' => 'wait'], ['from' => 'wait', 'to' => 'end']]];
    expect($validator->normalize($graph)['nodes'])->toHaveCount(3);

    foreach ([
        ['id' => 'x', 'type' => 'trigger', 'config' => ['event' => ['unsafe']]],
        ['id' => 'x', 'type' => 'wait', 'config' => ['seconds' => -1]],
        ['id' => 'x', 'type' => 'action', 'config' => ['endpoint' => 'https://example.invalid']],
        ['id' => 'x', 'type' => 'end', 'config' => ['anything' => true]],
    ] as $node) {
        expect(fn () => $validator->normalize(['schema_version' => 1, 'nodes' => [$node]]))
            ->toThrow(JourneyDefinitionException::class);
    }

    expect(fn () => $validator->normalize(['schema_version' => 1, 'nodes' => [['id' => 'missing-wait', 'type' => 'wait']]]))
        ->toThrow(JourneyDefinitionException::class, 'required_node_config_missing');
});

it('enforces never, after-exit, and bounded re-entry policies', function () {
    $guard = new JourneyEnrollmentGuard;
    $guard->assertReentryAllowed(JourneyReentryPolicy::Never, 0);
    $guard->assertReentryAllowed(JourneyReentryPolicy::AfterExit, 3, 'exited');
    $guard->assertReentryAllowed(JourneyReentryPolicy::Bounded, 2, maximumEnrollments: 3);

    expect(fn () => $guard->assertReentryAllowed(JourneyReentryPolicy::Never, 1))
        ->toThrow(JourneyDefinitionException::class, 'reentry_not_allowed');
    expect(fn () => $guard->assertReentryAllowed(JourneyReentryPolicy::AfterExit, 1, 'running'))
        ->toThrow(JourneyDefinitionException::class, 'reentry_not_allowed');
    expect(fn () => $guard->assertReentryAllowed(JourneyReentryPolicy::Bounded, 2, maximumEnrollments: 2))
        ->toThrow(JourneyDefinitionException::class, 'reentry_not_allowed');
});
