<?php

namespace App\Modules\Identity\Application\Billing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Offline trusted-source metering. No payment or plan mutation is exposed. */
final class RecordMeteredUsage
{
    public function execute(string $workspaceId, string $meter, string $sourceReference, int $units, bool $trustedSource): bool
    {
        if (! $trustedSource || $workspaceId === '' || $meter === '' || $sourceReference === ''
            || strlen($sourceReference) > 256 || $units < 1) {
            throw new AuthorizationException('Metered usage denied.');
        }

        return DB::transaction(function () use ($workspaceId, $meter, $sourceReference, $units): bool {
            // Lock the entitlement before idempotency and capacity checks so
            // concurrent requests cannot both consume the same remaining units.
            $entitlement = DB::table('workspace_meter_entitlements')->where('workspace_id', $workspaceId)
                ->where('meter', $meter)->lockForUpdate()->first();
            if ($entitlement === null || ! $entitlement->enabled) {
                throw new AuthorizationException('Meter entitlement is disabled.');
            }

            $fingerprint = hash('sha256', $sourceReference);
            $existing = DB::table('workspace_meter_events')->where('workspace_id', $workspaceId)
                ->where('meter', $meter)->where('source_fingerprint', $fingerprint)->first(['units']);
            if ($existing !== null) {
                if ((int) $existing->units !== $units) {
                    throw new AuthorizationException('Conflicting metering replay denied.');
                }

                return false;
            }

            if ($units > (int) $entitlement->limit_units - (int) $entitlement->used_units) {
                throw new AuthorizationException('Meter entitlement exhausted.');
            }

            DB::table('workspace_meter_events')->insert([
                'id' => (string) Str::uuid(), 'workspace_id' => $workspaceId,
                'meter' => $meter, 'source_fingerprint' => $fingerprint, 'units' => $units,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('workspace_meter_entitlements')->where('workspace_id', $workspaceId)
                ->where('meter', $meter)->increment('used_units', $units);

            return true;
        });
    }
}
