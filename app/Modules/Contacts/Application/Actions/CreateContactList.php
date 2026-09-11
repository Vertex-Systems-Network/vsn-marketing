<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Application\Actions;

use App\Modules\Contacts\Domain\Lists\ContactList;
use Illuminate\Support\Facades\DB;

class CreateContactList
{
    public function execute(int $workspaceId, string $name, ?string $description = null, array $metadata = []): ContactList
    {
        return DB::transaction(function () use ($workspaceId, $name, $description, $metadata) {
            return ContactList::create([
                'workspace_id' => $workspaceId,
                'name' => $name,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }
}
