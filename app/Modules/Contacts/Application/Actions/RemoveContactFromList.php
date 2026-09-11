<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Application\Actions;

use App\Modules\Contacts\Domain\Contact\Contact;
use App\Modules\Contacts\Domain\Lists\ContactList;
use Illuminate\Support\Facades\DB;

class RemoveContactFromList
{
    public function execute(ContactList $list, Contact $contact): bool
    {
        // Ensure workspace isolation
        if ($list->workspace_id !== $contact->workspace_id) {
            throw new \InvalidArgumentException('Contact and List must belong to the same workspace');
        }

        return DB::transaction(function () use ($list, $contact) {
            $detached = $list->contacts()->detach($contact->id);
            return $detached > 0;
        });
    }
}
