<?php

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\CustomerEventSubjectResolver;
use App\Modules\Events\Domain\CustomerEventSubject;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class DatabaseCustomerEventSubjectResolver implements CustomerEventSubjectResolver
{
    public function __construct(private DatabaseManager $database) {}

    public function resolve(
        string $workspaceId,
        ?string $brandScopeId,
        string $contactId,
        ?string $contactIdentityId,
    ): CustomerEventSubject {
        $connection = $this->database->connection();
        $query = $connection->table('contacts')
            ->select(['brand_id'])
            ->where('workspace_id', $workspaceId)
            ->where('id', $contactId);

        if ($brandScopeId !== null) {
            $query->where('brand_id', $brandScopeId);
        }

        $contact = $query->first();
        if (! $contact instanceof stdClass) {
            throw new AuthorizationException('Customer event contact access denied.');
        }

        if ($contactIdentityId !== null) {
            $identityExists = $connection->table('contact_identities')
                ->where('id', $contactIdentityId)
                ->where('workspace_id', $workspaceId)
                ->where('contact_id', $contactId)
                ->exists();

            if (! $identityExists) {
                throw new AuthorizationException('Customer event identity access denied.');
            }
        }

        return new CustomerEventSubject(
            contactId: $contactId,
            contactIdentityId: $contactIdentityId,
            brandId: $contact->brand_id === null ? null : (string) $contact->brand_id,
        );
    }
}
