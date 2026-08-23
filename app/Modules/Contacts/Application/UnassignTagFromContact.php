<?php

namespace App\Modules\Contacts\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Contacts\Domain\Contracts\TagRepository;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class UnassignTagFromContact
{
    public const AUDIT_ACTION = 'contact.tag.unassigned';

    public function __construct(
        private ContactRepository $contacts,
        private TagRepository $tags,
        private AuditRecorder $audit,
        private ContactTransaction $transaction,
    ) {}

    public function handle(TenantContext $context, string $tagId, string $contactId): bool
    {
        return $this->transaction->run(function () use ($context, $tagId, $contactId): bool {
            if ($this->contacts->find($context->workspaceId, $context->brandId, $contactId) === null) {
                throw new AuthorizationException('Contact access denied.');
            }
            if ($this->tags->find($context->workspaceId, $tagId) === null) {
                throw new AuthorizationException('Tag access denied.');
            }

            $changed = $this->tags->unassignContact($context->workspaceId, $tagId, $contactId);
            if (! $changed) {
                return false;
            }

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'tag',
                subjectId: $tagId,
                evidence: ['contact_id' => $contactId],
            );

            return true;
        });
    }
}
