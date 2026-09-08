<?php

namespace App\Modules\DeliveryEngine\Domain;

use InvalidArgumentException;

final readonly class DeliveryFairnessPolicy
{
    public function decide(
        string $workspaceId,
        int $workspaceInFlight,
        int $globalInFlight,
        int $workspaceWeight,
        int $activeWorkspaceWeightTotal,
        int $globalConcurrencyLimit,
        int $workspaceConcurrencyLimit,
    ): DeliveryFairnessDecision {
        if ($workspaceId === '') {
            throw new InvalidArgumentException('Workspace ID must not be empty.');
        }

        foreach ([
            'workspaceInFlight' => $workspaceInFlight,
            'globalInFlight' => $globalInFlight,
        ] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException($name.' must be non-negative.');
            }
        }

        foreach ([
            'workspaceWeight' => $workspaceWeight,
            'activeWorkspaceWeightTotal' => $activeWorkspaceWeightTotal,
            'globalConcurrencyLimit' => $globalConcurrencyLimit,
            'workspaceConcurrencyLimit' => $workspaceConcurrencyLimit,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException($name.' must be positive.');
            }
        }

        if ($workspaceWeight > $activeWorkspaceWeightTotal) {
            throw new InvalidArgumentException('Workspace weight cannot exceed the active workspace weight total.');
        }

        $weightedShare = intdiv($globalConcurrencyLimit * $workspaceWeight, $activeWorkspaceWeightTotal);
        $workspaceShare = min($workspaceConcurrencyLimit, max(1, $weightedShare));

        if ($globalInFlight >= $globalConcurrencyLimit) {
            return new DeliveryFairnessDecision(
                workspaceId: $workspaceId,
                admitted: false,
                workspaceShare: $workspaceShare,
                workspaceInFlight: $workspaceInFlight,
                globalInFlight: $globalInFlight,
                reason: 'global_capacity_exhausted',
            );
        }

        if ($workspaceInFlight >= $workspaceShare) {
            return new DeliveryFairnessDecision(
                workspaceId: $workspaceId,
                admitted: false,
                workspaceShare: $workspaceShare,
                workspaceInFlight: $workspaceInFlight,
                globalInFlight: $globalInFlight,
                reason: 'workspace_fair_share_exhausted',
            );
        }

        return new DeliveryFairnessDecision(
            workspaceId: $workspaceId,
            admitted: true,
            workspaceShare: $workspaceShare,
            workspaceInFlight: $workspaceInFlight,
            globalInFlight: $globalInFlight,
            reason: null,
        );
    }
}
