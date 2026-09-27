<?php

use App\Modules\Journeys\Application\JourneyEnrollmentGuard;
use App\Modules\Journeys\Domain\DurableJourneyWait;
use App\Modules\Journeys\Domain\JourneyActionGate;
use App\Modules\Journeys\Domain\JourneyConditionEvaluator;
use App\Modules\Journeys\Domain\JourneyConditionOperator;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyExecutionIdentity;
use App\Modules\Journeys\Domain\JourneyExecutionState;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use App\Modules\Journeys\Domain\JourneyReentryPolicy;
use App\Modules\Journeys\Domain\JourneyRuntimePolicy;
use App\Modules\Journeys\Domain\JourneyTrigger;

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

it('creates tenant-scoped durable wait descriptors without sleeping', function () {
    $policy = new JourneyRuntimePolicy(maxWaitSeconds: 3600);
    $now = new DateTimeImmutable('2026-09-27 12:00:00', new DateTimeZone('America/Los_Angeles'));
    $wait = DurableJourneyWait::schedule('workspace-1', 'execution-1', 'wait-1', $now, 30, $policy);
    expect($wait->wakeAt->format('Y-m-d H:i:s'))->toBe('2026-09-27 19:00:30')
        ->and($wait->idempotencyKey)->toBe(DurableJourneyWait::schedule('workspace-1', 'execution-1', 'wait-1', $now, 30, $policy)->idempotencyKey)
        ->and($wait->workspaceId)->toBe('workspace-1');
    expect(fn () => DurableJourneyWait::schedule('workspace-1', 'execution-1', 'wait-1', $now, 3601, $policy))
        ->toThrow(JourneyDefinitionException::class);
});

it('normalizes events to UTC and preserves explicit schedule timezone ordering', function () {
    $event = JourneyTrigger::event('workspace-1', 'event-9', new DateTimeImmutable('2026-09-27T12:00:00-07:00'));
    $schedule = JourneyTrigger::schedule('workspace-1', 'schedule-1', 'America/Los_Angeles', new DateTimeImmutable('2026-09-27T12:00:00-07:00'));
    expect($event->effectiveAt->format('H:i:s'))->toBe('19:00:00')
        ->and($event->timezone)->toBe('UTC')
        ->and($schedule->timezone)->toBe('America/Los_Angeles')
        ->and($event->idempotencyKey)->toBe(JourneyTrigger::event('workspace-1', 'event-9', new DateTimeImmutable('2026-09-27T19:00:00Z'))->idempotencyKey)
        ->and($schedule->idempotencyKey)->not->toBe(JourneyTrigger::schedule('workspace-1', 'schedule-2', 'America/Los_Angeles', new DateTimeImmutable('2026-09-27T12:00:00-07:00'))->idempotencyKey);
});

it('evaluates typed conditions deterministically without loose coercion', function () {
    $evaluator = new JourneyConditionEvaluator;
    expect($evaluator->evaluate(['score' => 10], 'score', JourneyConditionOperator::GreaterThan, '9'))->toBeTrue()
        ->and($evaluator->evaluate(['score' => 10], 'score', JourneyConditionOperator::Equals, '10'))->toBeFalse()
        ->and($evaluator->evaluate(['label' => 'new-customer'], 'label', JourneyConditionOperator::Contains, 'customer'))->toBeTrue()
        ->and($evaluator->evaluate([], 'missing', JourneyConditionOperator::Exists))->toBeFalse()
        ->and($evaluator->evaluate(['flag' => false], 'flag', JourneyConditionOperator::Equals, false))->toBeTrue();
    expect(fn () => $evaluator->evaluate([], 'score;delete', JourneyConditionOperator::Exists))->toThrow(JourneyDefinitionException::class);
});

it('fails closed for action capability consent policy authorization quota or idempotency gaps', function () {
    $gate = new JourneyActionGate;
    $all = ['provider_capability' => true, 'consent' => true, 'suppression_clear' => true, 'authorized' => true, 'quota_available' => true, 'idempotent' => true];
    expect($gate->blockers($all))->toBe([]);
    $blocked = $all;
    $blocked['consent'] = false;
    $blocked['suppression_clear'] = false;
    expect($gate->blockers($blocked))->toBe(['consent', 'suppression_clear'])
        ->and(fn () => $gate->assertAllowed($blocked))->toThrow(JourneyDefinitionException::class);
    expect($gate->blockers([]))->toHaveCount(6);
});

it('restricts graph condition operators and operand types to the typed evaluator contract', function () {
    $validator = new JourneyGraphValidator;
    $base = ['schema_version' => 1, 'nodes' => [
        ['id' => 'condition', 'type' => 'condition', 'config' => ['field' => 'profile.score', 'operator' => 'greater_than', 'value' => 10]],
        ['id' => 'end', 'type' => 'end'],
    ], 'edges' => [['from' => 'condition', 'to' => 'end', 'type' => 'true']]];
    expect($validator->normalize($base)['nodes'][0]['config']['operator'])->toBe('greater_than');

    $invalid = $base;
    $invalid['nodes'][0]['config']['operator'] = 'execute';
    expect(fn () => $validator->normalize($invalid))->toThrow(JourneyDefinitionException::class);
    $invalid = $base;
    $invalid['nodes'][0]['config']['value'] = 'ten';
    expect(fn () => $validator->normalize($invalid))->toThrow(JourneyDefinitionException::class);
    $validExists = $base;
    $validExists['nodes'][0]['config'] = ['field' => 'profile.score', 'operator' => 'exists'];
    expect($validator->normalize($validExists)['nodes'][0]['config']['operator'])->toBe('exists');
    $invalid = $validExists;
    $invalid['nodes'][0]['config']['value'] = true;
    expect(fn () => $validator->normalize($invalid))->toThrow(JourneyDefinitionException::class);
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
