<?php

use App\Modules\DeliveryEngine\Domain\DeliveryFairnessPolicy;
use InvalidArgumentException;

it('admits a workspace while it remains below its deterministic weighted share', function () {
    $decision = (new DeliveryFairnessPolicy())->decide(
        workspaceId: 'workspace-a',
        workspaceInFlight: 1,
        globalInFlight: 3,
        workspaceWeight: 1,
        activeWorkspaceWeightTotal: 2,
        globalConcurrencyLimit: 8,
        workspaceConcurrencyLimit: 6,
    );

    expect($decision->admitted)->toBeTrue()
        ->and($decision->workspaceShare)->toBe(4)
        ->and($decision->reason)->toBeNull();
});

it('backpressures a saturated workspace without consuming another workspace fair share', function () {
    $policy = new DeliveryFairnessPolicy();

    $saturated = $policy->decide(
        workspaceId: 'workspace-a',
        workspaceInFlight: 4,
        globalInFlight: 4,
        workspaceWeight: 1,
        activeWorkspaceWeightTotal: 2,
        globalConcurrencyLimit: 8,
        workspaceConcurrencyLimit: 8,
    );
    $peer = $policy->decide(
        workspaceId: 'workspace-b',
        workspaceInFlight: 0,
        globalInFlight: 4,
        workspaceWeight: 1,
        activeWorkspaceWeightTotal: 2,
        globalConcurrencyLimit: 8,
        workspaceConcurrencyLimit: 8,
    );

    expect($saturated->admitted)->toBeFalse()
        ->and($saturated->reason)->toBe('workspace_fair_share_exhausted')
        ->and($peer->admitted)->toBeTrue()
        ->and($peer->workspaceShare)->toBe(4);
});

it('honors the stricter workspace concurrency ceiling', function () {
    $decision = (new DeliveryFairnessPolicy())->decide(
        workspaceId: 'workspace-a',
        workspaceInFlight: 2,
        globalInFlight: 2,
        workspaceWeight: 3,
        activeWorkspaceWeightTotal: 4,
        globalConcurrencyLimit: 12,
        workspaceConcurrencyLimit: 2,
    );

    expect($decision->workspaceShare)->toBe(2)
        ->and($decision->admitted)->toBeFalse()
        ->and($decision->reason)->toBe('workspace_fair_share_exhausted');
});

it('reserves at least one slot for every active positive-weight workspace', function () {
    $decision = (new DeliveryFairnessPolicy())->decide(
        workspaceId: 'workspace-small',
        workspaceInFlight: 0,
        globalInFlight: 0,
        workspaceWeight: 1,
        activeWorkspaceWeightTotal: 100,
        globalConcurrencyLimit: 8,
        workspaceConcurrencyLimit: 8,
    );

    expect($decision->workspaceShare)->toBe(1)
        ->and($decision->admitted)->toBeTrue();
});

it('blocks every workspace once global capacity is exhausted', function () {
    $decision = (new DeliveryFairnessPolicy())->decide(
        workspaceId: 'workspace-a',
        workspaceInFlight: 0,
        globalInFlight: 8,
        workspaceWeight: 1,
        activeWorkspaceWeightTotal: 2,
        globalConcurrencyLimit: 8,
        workspaceConcurrencyLimit: 8,
    );

    expect($decision->admitted)->toBeFalse()
        ->and($decision->reason)->toBe('global_capacity_exhausted');
});

it('fails closed on invalid fairness evidence', function (array $input) {
    expect(fn () => (new DeliveryFairnessPolicy())->decide(...$input))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'negative in-flight' => [[
        'workspaceId' => 'workspace-a',
        'workspaceInFlight' => -1,
        'globalInFlight' => 0,
        'workspaceWeight' => 1,
        'activeWorkspaceWeightTotal' => 1,
        'globalConcurrencyLimit' => 1,
        'workspaceConcurrencyLimit' => 1,
    ]],
    'zero weight' => [[
        'workspaceId' => 'workspace-a',
        'workspaceInFlight' => 0,
        'globalInFlight' => 0,
        'workspaceWeight' => 0,
        'activeWorkspaceWeightTotal' => 1,
        'globalConcurrencyLimit' => 1,
        'workspaceConcurrencyLimit' => 1,
    ]],
    'weight exceeds total' => [[
        'workspaceId' => 'workspace-a',
        'workspaceInFlight' => 0,
        'globalInFlight' => 0,
        'workspaceWeight' => 2,
        'activeWorkspaceWeightTotal' => 1,
        'globalConcurrencyLimit' => 1,
        'workspaceConcurrencyLimit' => 1,
    ]],
]);
