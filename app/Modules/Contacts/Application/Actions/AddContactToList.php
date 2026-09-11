<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Application\Actions;

use App\Modules\Contacts\Domain\Contact\Contact;
use App\Modules\Contacts\Domain\Lists\ContactList;
use Illuminate\Support\Facades\DB;

class AddContactToList
{
    public function execute(ContactList $list, Contact $contact): bool
    {
        // Ensure workspace isolation
        if ($list->workspace_id !== $contact->workspace_id) {
            throw new \InvalidArgumentException('Contact and List must belong to the same workspace');
        }

        return DB::transaction(function () use ($list, $contact) {
            // Idempotent: attach only if not already attached (syncWithoutDetaching returns array of attached IDs)
            $attached = $list->contacts()->syncWithoutDetaching([$contact->id => ['joined_at' => now()]]);
            return count($attached) > 0;
        });
    }
}
