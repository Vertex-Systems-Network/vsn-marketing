<?php

namespace App\Modules\AI\Infrastructure;

use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Requires an explicitly configured workspace budget; absent rows deny spending. */
final class DatabaseAiBudgetLedger implements AiBudgetLedger
{
    public function reserve(string $workspaceId, string $traceId, int $minorUnits): bool
    {
        if ($workspaceId === '' || $traceId === '' || strlen($traceId) > 128 || $minorUnits < 0) {
            throw new InvalidArgumentException('Invalid AI budget reservation.');
        }

        $period = gmdate('Y-m-d');

        return DB::transaction(function () use ($workspaceId, $traceId, $minorUnits, $period): bool {
            $budget = DB::table('ai_workspace_budgets')
                ->where('workspace_id', $workspaceId)->where('period_utc', $period)
                ->lockForUpdate()->first();

            if ($budget === null) {
                return false;
            }

            // Locking the parent budget row serializes reservations on PostgreSQL.
            if (DB::table('ai_budget_reservations')->where('workspace_id', $workspaceId)->where('trace_id', $traceId)->exists()) {
                return false;
            }

            $available = (int) $budget->limit_minor - (int) $budget->reserved_minor - (int) $budget->spent_minor;
            if ($minorUnits > $available) {
                return false;
            }

            DB::table('ai_workspace_budgets')->where('workspace_id', $workspaceId)->where('period_utc', $period)
                ->update(['reserved_minor' => (int) $budget->reserved_minor + $minorUnits, 'updated_at' => now()]);
            DB::table('ai_budget_reservations')->insert([
                'workspace_id' => $workspaceId, 'trace_id' => $traceId, 'period_utc' => $period,
                'reserved_minor' => $minorUnits, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    public function settle(string $workspaceId, string $traceId, int $actualMinorUnits): void
    {
        if ($actualMinorUnits < 0) {
            throw new InvalidArgumentException('Actual AI cost cannot be negative.');
        }

        DB::transaction(function () use ($workspaceId, $traceId, $actualMinorUnits): void {
            $reservation = DB::table('ai_budget_reservations')
                ->where('workspace_id', $workspaceId)->where('trace_id', $traceId)->first();
            if ($reservation === null) {
                throw new RuntimeException('AI budget reservation is missing.');
            }

            $budget = DB::table('ai_workspace_budgets')
                ->where('workspace_id', $workspaceId)->where('period_utc', $reservation->period_utc)
                ->lockForUpdate()->first();
            if ($budget === null) {
                throw new RuntimeException('AI workspace budget is missing.');
            }

            $reservation = DB::table('ai_budget_reservations')
                ->where('workspace_id', $workspaceId)->where('trace_id', $traceId)->lockForUpdate()->first();
            if ($reservation->status !== 'reserved' || $actualMinorUnits > $reservation->reserved_minor) {
                throw new RuntimeException('AI budget settlement exceeds or duplicates its reservation.');
            }

            DB::table('ai_budget_reservations')->where('workspace_id', $workspaceId)->where('trace_id', $traceId)
                ->update(['actual_minor' => $actualMinorUnits, 'status' => 'settled', 'updated_at' => now()]);
            DB::table('ai_workspace_budgets')->where('workspace_id', $workspaceId)->where('period_utc', $reservation->period_utc)
                ->update([
                    'reserved_minor' => (int) $budget->reserved_minor - (int) $reservation->reserved_minor,
                    'spent_minor' => (int) $budget->spent_minor + $actualMinorUnits,
                    'updated_at' => now(),
                ]);
        }, 3);
    }
}
