<?php

use App\Modules\DeliveryEngine\Application\Frequency\EvaluateFrequencyPolicy;
use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\Frequency\FrequencyCounterSnapshot;
use App\Modules\DeliveryEngine\Domain\Frequency\FrequencyPolicy;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;

function task0028FrequencyPolicy(): FrequencyPolicy
{
    return new FrequencyPolicy(
        id: 'frequency-policy-a',
        workspaceId: 'workspace-a',
        messagePurpose: MessageIntentType::Marketing,
        recipientScope: 'recipient-scope-a',
        windowSeconds: 3600,
        maxMessages: 3,
        version: 'frequency-v1',
        effectiveAt: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
    );
}

function task0028FrequencyCounter(
    FrequencyPolicy $policy,
    int $count,
    array $countedOperationKeys = [],
    ?DateTimeImmutable $evaluatedAt = null,
): FrequencyCounterSnapshot {
    $evaluatedAt ??= new DateTimeImmutable('2026-09-17T12:15:00+00:00');
    $window = $policy->windowFor($evaluatedAt);

    return new FrequencyCounterSnapshot(
        workspaceId: $policy->workspaceId,
        policyId: $policy->id,
        recipientScope: $policy->recipientScope,
        windowStart: $window['start'],
        windowEnd: $window['end'],
        count: $count,
        countedOperationKeys: $countedOperationKeys,
        observedAt: $evaluatedAt,
    );
}

it('fails closed when the frequency policy is missing', function () {
    $result = (new EvaluateFrequencyPolicy)->evaluate(
        workspaceId: 'workspace-a',
        messagePurpose: MessageIntentType::Marketing,
        recipientScope: 'recipient-scope-a',
        operationKey: 'delivery-attempt-a',
        policy: null,
        counter: null,
        evaluatedAt: new DateTimeImmutable('2026-09-17T12:15:00+00:00'),
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Review)
        ->and($result->reasons)->toContain('frequency_policy_missing');
});

it('denies foreign-workspace or recipient-scope policy reuse', function () {
    $policy = task0028FrequencyPolicy();
    $evaluator = new EvaluateFrequencyPolicy;
    $at = new DateTimeImmutable('2026-09-17T12:15:00+00:00');

    $workspaceResult = $evaluator->evaluate(
        'workspace-b',
        MessageIntentType::Marketing,
        'recipient-scope-a',
        'delivery-attempt-a',
        $policy,
        null,
        $at,
    );

    $scopeResult = $evaluator->evaluate(
        'workspace-a',
        MessageIntentType::Marketing,
        'recipient-scope-b',
        'delivery-attempt-a',
        $policy,
        null,
        $at,
    );

    expect($workspaceResult->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($workspaceResult->reasons)->toContain('frequency_policy_workspace_mismatch')
        ->and($scopeResult->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($scopeResult->reasons)->toContain('frequency_policy_scope_mismatch');
});

it('uses deterministic aligned windows and requires trusted counter state', function () {
    $policy = task0028FrequencyPolicy();
    $at = new DateTimeImmutable('2026-09-17T12:15:00+00:00');
    $window = $policy->windowFor($at);

    expect($window['start']->format(DATE_ATOM))->toBe('2026-09-17T12:00:00+00:00')
        ->and($window['end']->format(DATE_ATOM))->toBe('2026-09-17T13:00:00+00:00');

    $result = (new EvaluateFrequencyPolicy)->evaluate(
        'workspace-a',
        MessageIntentType::Marketing,
        'recipient-scope-a',
        'delivery-attempt-a',
        $policy,
        null,
        $at,
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Review)
        ->and($result->reasons)->toContain('frequency_counter_missing');
});

it('allows below the cap and returns the deterministic next count', function () {
    $policy = task0028FrequencyPolicy();
    $at = new DateTimeImmutable('2026-09-17T12:15:00+00:00');
    $counter = task0028FrequencyCounter($policy, 2, [], $at);

    $result = (new EvaluateFrequencyPolicy)->evaluate(
        'workspace-a',
        MessageIntentType::Marketing,
        'recipient-scope-a',
        'delivery-attempt-a',
        $policy,
        $counter,
        $at,
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Allow)
        ->and($result->currentCount)->toBe(2)
        ->and($result->nextCount)->toBe(3)
        ->and($result->replay)->toBeFalse();
});

it('denies a new operation when the cap is reached', function () {
    $policy = task0028FrequencyPolicy();
    $at = new DateTimeImmutable('2026-09-17T12:15:00+00:00');
    $counter = task0028FrequencyCounter($policy, 3, [], $at);

    $result = (new EvaluateFrequencyPolicy)->evaluate(
        'workspace-a',
        MessageIntentType::Marketing,
        'recipient-scope-a',
        'delivery-attempt-new',
        $policy,
        $counter,
        $at,
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Deny)
        ->and($result->reasons)->toContain('frequency_limit_reached')
        ->and($result->nextCount)->toBe(3);
});

it('does not consume capacity twice for the same replay-safe operation key', function () {
    $policy = task0028FrequencyPolicy();
    $at = new DateTimeImmutable('2026-09-17T12:15:00+00:00');
    $counter = task0028FrequencyCounter($policy, 3, ['delivery-attempt-a'], $at);

    $result = (new EvaluateFrequencyPolicy)->evaluate(
        'workspace-a',
        MessageIntentType::Marketing,
        'recipient-scope-a',
        'delivery-attempt-a',
        $policy,
        $counter,
        $at,
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Allow)
        ->and($result->replay)->toBeTrue()
        ->and($result->currentCount)->toBe(3)
        ->and($result->nextCount)->toBe(3)
        ->and($result->reasons)->toContain('frequency_operation_already_counted');
});

it('fails closed on mismatched counter windows', function () {
    $policy = task0028FrequencyPolicy();
    $at = new DateTimeImmutable('2026-09-17T12:15:00+00:00');
    $window = $policy->windowFor($at);
    $counter = new FrequencyCounterSnapshot(
        workspaceId: 'workspace-a',
        policyId: $policy->id,
        recipientScope: $policy->recipientScope,
        windowStart: $window['start']->modify('-1 hour'),
        windowEnd: $window['start'],
        count: 1,
        countedOperationKeys: [],
        observedAt: $at,
    );

    $result = (new EvaluateFrequencyPolicy)->evaluate(
        'workspace-a',
        MessageIntentType::Marketing,
        'recipient-scope-a',
        'delivery-attempt-a',
        $policy,
        $counter,
        $at,
    );

    expect($result->outcome)->toBe(EligibilityOutcome::Review)
        ->and($result->reasons)->toContain('frequency_counter_window_mismatch');
});
