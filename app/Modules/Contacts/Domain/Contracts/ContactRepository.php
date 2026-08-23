<?php

namespace App\Modules\Contacts\Domain\Contracts;

use App\Modules\Contacts\Domain\Contact;
use App\Modules\Contacts\Domain\ContactIdentity;
use App\Modules\Contacts\Domain\NormalizedContactIdentity;

interface ContactRepository
{
    public function create(
        string $id,
        string $workspaceId,
        ?string $brandId,
        ?string $companyId,
        ?string $firstName,
        ?string $lastName,
        ?string $displayName,
    ): Contact;

    public function update(
        string $workspaceId,
        ?string $brandScopeId,
        string $contactId,
        ?string $companyId,
        ?string $firstName,
        ?string $lastName,
        ?string $displayName,
    ): Contact;

    public function addIdentity(
        string $id,
        string $workspaceId,
        ?string $brandScopeId,
        string $contactId,
        NormalizedContactIdentity $identity,
    ): ContactIdentity;

    public function find(string $workspaceId, ?string $brandScopeId, string $contactId): ?Contact;
}
