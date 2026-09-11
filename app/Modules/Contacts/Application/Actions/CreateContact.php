<?php

namespace App\Modules\Contacts\Application\Actions;

use App\Modules\Contacts\Domain\Contact\Contact;
use App\Modules\Identity\Domain\Tenancy\Workspace;

/**
 * Create a new contact within a workspace.
 * Enforces workspace isolation and email normalization.
 */
final class CreateContact
{
    public function execute(
        Workspace $workspace,
        string $email,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $phone = null,
        array $metadata = []
    ): Contact {
        $normalizedEmail = Contact::normalizeEmail($email);

        // Check for existing contact in this workspace with same email
        $existing = Contact::forWorkspace($workspace)
            ->where('primary_email', $normalizedEmail)
            ->first();

        if ($existing instanceof Contact) {
            throw new \RuntimeException(
                "Contact with email {$normalizedEmail} already exists in workspace {$workspace->id}"
            );
        }

        $displayName = trim("{$firstName} {$lastName}");

        return Contact::create([
            'workspace_id' => $workspace->id,
            'primary_email' => $normalizedEmail,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $displayName ?: null,
            'primary_phone' => $phone,
            'status' => 'active',
            'metadata' => $metadata ?: null,
        ]);
    }
}
