<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\AiBudgetLedger;

/** Safe default: no provider spend until a durable atomic ledger is bound. */
final class DenyingAiBudgetLedger implements AiBudgetLedger
{
    public function reserve(string $workspaceId, string $traceId, int $minorUnits): bool
    {
        return false;
    }

    public function settle(string $workspaceId, string $traceId, int $actualMinorUnits): void
    {
        // No reservation can be created by this binding.
    }
}
