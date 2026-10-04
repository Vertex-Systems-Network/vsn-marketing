<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiBudgetLedger
{
    /** Atomically reserve a bounded amount across all workers, or deny. */
    public function reserve(string $workspaceId, string $traceId, int $minorUnits): bool;

    /** Release unused reservation; account actual usage independently. */
    public function settle(string $workspaceId, string $traceId, int $actualMinorUnits): void;
}
