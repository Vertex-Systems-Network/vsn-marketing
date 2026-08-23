<?php

namespace App\Modules\Contacts\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Contacts\Domain\ContactList;
use App\Modules\Contacts\Domain\Contracts\ContactListRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use InvalidArgumentException;

final readonly class CreateContactList
{
    public const AUDIT_ACTION = 'contact_list.created';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private ContactListRepository $lists,
        private AuditRecorder $audit,
        private ContactTransaction $transaction,
    ) {}

    public function handle(TenantContext $context, string $name): ContactList
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Contact list name is required.');
        }

        return $this->transaction->run(function () use ($context, $name): ContactList {
            $list = $this->lists->create(
                id: $this->identifiers->next(),
                workspaceId: $context->workspaceId,
                name: $name,
            );

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'contact_list',
                subjectId: $list->id,
                evidence: ['name_present' => true],
            );

            return $list;
        });
    }
}
