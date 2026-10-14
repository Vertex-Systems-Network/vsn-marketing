<?php

namespace App\Modules\Identity\Application\Governance;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Counts only; external copies and unstructured references require manual discovery. */
final class ContactDataInventory
{
    private const TABLES = [
        'contact_identities', 'contact_list_memberships', 'contact_tag_assignments',
        'consent_records', 'customer_events', 'delivery_recipient_snapshots',
        'suppression_records', 'preference_records', 'unsubscribe_token_scopes',
        'contact_legal_holds',
    ];

    /** @return array{local_counts: array<string, int>, external_inventory_complete: false} */
    public function snapshot(string $workspaceId, string $contactId): array
    {
        if ($workspaceId === '' || $contactId === '' || ! DB::table('contacts')
            ->where('workspace_id', $workspaceId)->where('id', $contactId)->exists()) {
            throw new AuthorizationException('Contact inventory denied.');
        }

        $counts = ['contacts' => 1];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->where('workspace_id', $workspaceId)
                ->where('contact_id', $contactId)->count();
        }

        return ['local_counts' => $counts, 'external_inventory_complete' => false];
    }
}
