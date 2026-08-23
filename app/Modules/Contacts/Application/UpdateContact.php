<?php

namespace App\Modules\Contacts\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Contacts\Domain\Contact;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Identity\Domain\Tenancy\TenantContext;

final readonly class UpdateContact
{
    public const AUDIT_ACTION = 'contact.updated';

    public function __construct(
        private ContactRepository $contacts,
        private AuditRecorder $audit,
        private ContactTransaction $transaction,
    ) {}

    public function handle(
        TenantContext $context,
        string $contactId,
        ?string $companyId = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $displayName = null,
    ): Contact {
        $firstName = $this->clean($firstName);
        $lastName = $this->clean($lastName);
        $displayName = $this->clean($displayName);

        return $this->transaction->run(function () use (
            $context,
            $contactId,
            $companyId,
            $firstName,
            $lastName,
            $displayName,
        ): Contact {
            $contact = $this->contacts->update(
                workspaceId: $context->workspaceId,
                brandScopeId: $context->brandId,
                contactId: $contactId,
                companyId: $companyId,
                firstName: $firstName,
                lastName: $lastName,
                displayName: $displayName,
            );

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $contact->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'contact',
                subjectId: $contact->id,
                evidence: ['fields' => ['company_id', 'first_name', 'last_name', 'display_name']],
            );

            return $contact;
        });
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
