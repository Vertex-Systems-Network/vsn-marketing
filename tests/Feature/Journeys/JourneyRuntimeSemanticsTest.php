<?php

use App\Modules\Events\Domain\CanonicalEvent;
use App\Modules\Journeys\Application\DispatchJourneyAction;
use App\Modules\Journeys\Domain\DurableJourneyWait;
use App\Modules\Journeys\Domain\JourneyBranchResolver;
use App\Modules\Journeys\Domain\JourneyConditionEvaluator;
use App\Modules\Journeys\Domain\JourneyConditionOperator;
use App\Modules\Journeys\Domain\JourneyEventTriggerMatcher;
use App\Modules\Journeys\Domain\JourneyExecutionState;
use App\Modules\Journeys\Domain\JourneyRuntimePolicy;
use App\Modules\Journeys\Domain\JourneyTerminalEvaluator;
use App\Modules\Journeys\Domain\JourneyTerminalOutcome;
use App\Modules\Journeys\Domain\JourneyTrigger;
use App\Modules\Journeys\Domain\JourneyTriggerOrdering;
use App\Modules\Journeys\Domain\JourneyWaitEvaluator;
use App\Modules\Journeys\Domain\JourneyWaitOutcome;

it('orders late-delivered events by occurrence time and keeps redelivery identity stable', function () {
    $occurredAt = new DateTimeImmutable('2026-09-27T12:00:00Z');
    $late = new CanonicalEvent(
        eventId: 'evt-late', eventType: 'customer.updated', occurredAt: $occurredAt,
        receivedAt: $occurredAt->modify('+2 hours'), workspaceId: 'workspace-1', brandId: null,
        subjects: ['customer' => 'customer-1'], source: 'test', sourceEventId: 'late-source-1',
        schemaVersion: 1, payload: [], sourceMetadata: [],
    );
    $matcher = new JourneyEventTriggerMatcher;
    $node = ['type' => 'trigger', 'config' => ['event' => 'customer.updated']];
    $trigger = $matcher->match('workspace-1', $node, $late);
    $redelivery = $matcher->match('workspace-1', $node, $late);
    $onTime = JourneyTrigger::event('workspace-1', 'evt-on-time', $occurredAt->modify('+30 minutes'));

    expect($trigger)->not->toBeNull()
        ->and($trigger->effectiveAt)->toEqual($occurredAt)
        ->and($trigger->idempotencyKey)->toBe($redelivery->idempotencyKey)
        ->and((new JourneyTriggerOrdering)->compare($trigger, $onTime))->toBeLessThan(0);
});

it('runs the canonical trigger through wait branch gated action and goal semantics', function () {
    $occurredAt = new DateTimeImmutable('2026-09-27T12:00:00Z');
    $event = new CanonicalEvent(
        eventId: 'evt-runtime-1',
        eventType: 'customer.created',
        occurredAt: $occurredAt,
        receivedAt: $occurredAt->modify('+1 second'),
        workspaceId: 'workspace-1',
        brandId: null,
        subjects: ['customer' => 'customer-1'],
        source: 'test',
        sourceEventId: 'source-evt-1',
        schemaVersion: 1,
        payload: ['tier' => 'gold'],
        sourceMetadata: [],
    );

    $trigger = (new JourneyEventTriggerMatcher)->match('workspace-1', [
        'type' => 'trigger', 'config' => ['event' => 'customer.created'],
    ], $event);
    expect($trigger)->not->toBeNull();

    $policy = new JourneyRuntimePolicy(maxWaitSeconds: 3600);
    $wait = DurableJourneyWait::schedule('workspace-1', 'execution-1', 'wait-1', $occurredAt, 300, $policy);
    expect((new JourneyWaitEvaluator)->evaluate(
        $wait,
        'workspace-1',
        $occurredAt->modify('+30 seconds'),
        ['tier' => $event->payload['tier']],
        ['field' => 'tier', 'operator' => 'equals', 'value' => 'gold'],
    ))->toBe(JourneyWaitOutcome::Ready);

    $branch = (new JourneyConditionEvaluator)->evaluate(
        $event->payload,
        'tier',
        JourneyConditionOperator::Equals,
        'gold',
    );
    $nextNode = (new JourneyBranchResolver)->resolve('branch', $branch, [
        ['from' => 'branch', 'to' => 'action', 'type' => 'true'],
        ['from' => 'branch', 'to' => 'exit', 'type' => 'false'],
    ]);
    expect($nextNode)->toBe('action');

    $sent = false;
    (new DispatchJourneyAction)->handle([
        'provider_capability' => true,
        'consent' => true,
        'suppression_clear' => true,
        'authorized' => true,
        'quota_available' => true,
        'idempotent' => true,
    ], function () use (&$sent): void {
        $sent = true;
    });
    expect($sent)->toBeTrue();

    $outcome = (new JourneyTerminalEvaluator)->evaluate('workspace-1', [
        'type' => 'goal', 'config' => ['event' => 'customer.created'],
    ], $event);
    $state = new JourneyExecutionState('workspace-1', 'execution-1');
    $state->transition('running');
    $state->applyTerminalOutcome($outcome);
    expect($outcome)->toBe(JourneyTerminalOutcome::GoalAchieved)
        ->and($state->status)->toBe('succeeded');
});
