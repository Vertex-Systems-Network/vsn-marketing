<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Application\Actions;

use App\Modules\Contacts\Domain\Contact\Contact;
use App\Modules\Contacts\Domain\Tag\Tag;
use Illuminate\Support\Facades\DB;

class AssignTagToContact
{
    public function execute(Tag $tag, Contact $contact): bool
    {
        // Ensure workspace isolation
        if ($tag->workspace_id !== $contact->workspace_id) {
            throw new \InvalidArgumentException('Contact and Tag must belong to the same workspace');
        }

        return DB::transaction(function () use ($tag, $contact) {
            // Idempotent: attach only if not already attached
            $attached = $tag->contacts()->syncWithoutDetaching([$contact->id => ['assigned_at' => now()]]);
            return count($attached) > 0;
        });
    }
}
