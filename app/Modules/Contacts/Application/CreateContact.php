<?php

namespace App\Modules\Contacts\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Contacts\Domain\Contact;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;

final readonly class CreateContact
{
    public const AUDIT_ACTION = 'contact.created';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private ContactRepository $contacts,
        private AuditRecorder $audit,
        private ContactTransaction $transaction,
    ) {}

    public function handle(
        TenantContext $context,
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
            $companyId,
            $firstName,
            $lastName,
            $displayName,
        ): Contact {
            $contact = $this->contacts->create(
                id: $this->identifiers->next(),
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                companyId: $companyId,
                firstName: $firstName,
                lastName: $lastName,
                displayName: $displayName,
            );

            $fields = [];
            foreach ([
                'company_id' => $companyId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $displayName,
            ] as $field => $value) {
                if ($value !== null) {
                    $fields[] = $field;
                }
            }

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'contact',
                subjectId: $contact->id,
                evidence: ['fields' => $fields],
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
