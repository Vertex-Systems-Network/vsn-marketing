<?php

namespace App\Modules\Contacts\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Contacts\Domain\Contracts\ContactListRepository;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AddContactToList
{
    public const AUDIT_ACTION = 'contact_list.membership.added';

    public function __construct(
        private ContactRepository $contacts,
        private ContactListRepository $lists,
        private AuditRecorder $audit,
        private ContactTransaction $transaction,
    ) {}

    public function handle(TenantContext $context, string $listId, string $contactId): bool
    {
        return $this->transaction->run(function () use ($context, $listId, $contactId): bool {
            if ($this->contacts->find($context->workspaceId, $context->brandId, $contactId) === null) {
                throw new AuthorizationException('Contact access denied.');
            }
            if ($this->lists->find($context->workspaceId, $listId) === null) {
                throw new AuthorizationException('Contact list access denied.');
            }

            $changed = $this->lists->addContact($context->workspaceId, $listId, $contactId);
            if (! $changed) {
                return false;
            }

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'contact_list',
                subjectId: $listId,
                evidence: ['contact_id' => $contactId],
            );

            return true;
        });
    }
}
