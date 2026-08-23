<?php

namespace App\Modules\Contacts\Infrastructure;

use App\Modules\Contacts\Domain\Contact;
use App\Modules\Contacts\Domain\ContactIdentity;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Contacts\Domain\NormalizedContactIdentity;
use App\Modules\Core\Domain\Contracts\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use stdClass;

final readonly class DatabaseContactRepository implements ContactRepository
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function create(
        string $id,
        string $workspaceId,
        ?string $brandId,
        ?string $companyId,
        ?string $firstName,
        ?string $lastName,
        ?string $displayName,
    ): Contact {
        $this->assertBrandInWorkspace($workspaceId, $brandId);
        $this->assertCompanyCompatible($workspaceId, $brandId, $companyId);
        $now = $this->clock->now();

        $this->database->connection()->table('contacts')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'brand_id' => $brandId,
            'company_id' => $companyId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $displayName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new Contact($id, $workspaceId, $brandId, $companyId, $firstName, $lastName, $displayName);
    }

    public function update(
        string $workspaceId,
        ?string $brandScopeId,
        string $contactId,
        ?string $companyId,
        ?string $firstName,
        ?string $lastName,
        ?string $displayName,
    ): Contact {
        $contact = $this->find($workspaceId, $brandScopeId, $contactId);
        if ($contact === null) {
            throw new AuthorizationException('Contact access denied.');
        }

        $this->assertCompanyCompatible($workspaceId, $contact->brandId, $companyId);

        $this->database->connection()->table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('id', $contactId)
            ->update([
                'company_id' => $companyId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $displayName,
                'updated_at' => $this->clock->now(),
            ]);

        return new Contact(
            id: $contact->id,
            workspaceId: $contact->workspaceId,
            brandId: $contact->brandId,
            companyId: $companyId,
            firstName: $firstName,
            lastName: $lastName,
            displayName: $displayName,
        );
    }

    public function addIdentity(
        string $id,
        string $workspaceId,
        ?string $brandScopeId,
        string $contactId,
        NormalizedContactIdentity $identity,
    ): ContactIdentity {
        if ($this->find($workspaceId, $brandScopeId, $contactId) === null) {
            throw new AuthorizationException('Contact access denied.');
        }

        $connection = $this->database->connection();
        $duplicate = $connection->table('contact_identities')
            ->where('workspace_id', $workspaceId)
            ->where('type', $identity->type->value)
            ->where('normalized_value', $identity->normalizedValue)
            ->exists();

        if ($duplicate) {
            throw new InvalidArgumentException('Contact identity already exists in this workspace.');
        }

        if ($identity->provider !== null && $identity->providerReference !== null) {
            $providerDuplicate = $connection->table('contact_identities')
                ->where('workspace_id', $workspaceId)
                ->where('provider', $identity->provider)
                ->where('provider_reference', $identity->providerReference)
                ->exists();

            if ($providerDuplicate) {
                throw new InvalidArgumentException('Provider reference already belongs to a contact in this workspace.');
            }
        }

        $now = $this->clock->now();
        $connection->table('contact_identities')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'contact_id' => $contactId,
            'type' => $identity->type->value,
            'value' => $identity->value,
            'normalized_value' => $identity->normalizedValue,
            'provider' => $identity->provider,
            'provider_reference' => $identity->providerReference,
            'verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new ContactIdentity(
            id: $id,
            workspaceId: $workspaceId,
            contactId: $contactId,
            type: $identity->type,
            value: $identity->value,
            normalizedValue: $identity->normalizedValue,
            provider: $identity->provider,
            providerReference: $identity->providerReference,
        );
    }

    public function find(string $workspaceId, ?string $brandScopeId, string $contactId): ?Contact
    {
        $query = $this->database->connection()->table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('id', $contactId);

        if ($brandScopeId !== null) {
            $query->where('brand_id', $brandScopeId);
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function assertBrandInWorkspace(string $workspaceId, ?string $brandId): void
    {
        if ($brandId === null) {
            return;
        }

        $exists = $this->database->connection()->table('brands')
            ->where('id', $brandId)
            ->where('workspace_id', $workspaceId)
            ->exists();

        if (! $exists) {
            throw new AuthorizationException('Brand access denied.');
        }
    }

    private function assertCompanyCompatible(string $workspaceId, ?string $contactBrandId, ?string $companyId): void
    {
        if ($companyId === null) {
            return;
        }

        $company = $this->database->connection()->table('companies')
            ->select(['brand_id'])
            ->where('id', $companyId)
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $company instanceof stdClass) {
            throw new AuthorizationException('Company access denied.');
        }

        $companyBrandId = $company->brand_id === null ? null : (string) $company->brand_id;
        if ($contactBrandId === null && $companyBrandId !== null) {
            throw new AuthorizationException('Company brand scope denied.');
        }

        if ($contactBrandId !== null && $companyBrandId !== null && $companyBrandId !== $contactBrandId) {
            throw new AuthorizationException('Company brand scope denied.');
        }
    }

    private function hydrate(stdClass $row): Contact
    {
        return new Contact(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            brandId: $row->brand_id === null ? null : (string) $row->brand_id,
            companyId: $row->company_id === null ? null : (string) $row->company_id,
            firstName: $row->first_name === null ? null : (string) $row->first_name,
            lastName: $row->last_name === null ? null : (string) $row->last_name,
            displayName: $row->display_name === null ? null : (string) $row->display_name,
        );
    }
}
