<?php

namespace App\Modules\Contacts\Application;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Contacts\Domain\ContactIdentity;
use App\Modules\Contacts\Domain\ContactIdentityNormalizer;
use App\Modules\Contacts\Domain\ContactIdentityType;
use App\Modules\Contacts\Domain\Contracts\ContactRepository;
use App\Modules\Contacts\Domain\Contracts\ContactTransaction;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Identity\Domain\Tenancy\TenantContext;

final readonly class AddContactIdentity
{
    public const AUDIT_ACTION = 'contact.identity.added';

    public function __construct(
        private IdentifierGenerator $identifiers,
        private ContactIdentityNormalizer $normalizer,
        private ContactRepository $contacts,
        private AuditRecorder $audit,
        private ContactTransaction $transaction,
    ) {}

    public function handle(
        TenantContext $context,
        string $contactId,
        ContactIdentityType $type,
        string $value,
        ?string $provider = null,
        ?string $providerReference = null,
    ): ContactIdentity {
        $identity = $this->normalizer->normalize($type, $value, $provider, $providerReference);

        return $this->transaction->run(function () use ($context, $contactId, $identity): ContactIdentity {
            $contactIdentity = $this->contacts->addIdentity(
                id: $this->identifiers->next(),
                workspaceId: $context->workspaceId,
                brandScopeId: $context->brandId,
                contactId: $contactId,
                identity: $identity,
            );

            $this->audit->record(
                workspaceId: $context->workspaceId,
                brandId: $context->brandId,
                actorId: $context->actorId,
                action: self::AUDIT_ACTION,
                subjectType: 'contact',
                subjectId: $contactId,
                evidence: [
                    'identity_id' => $contactIdentity->id,
                    'type' => $identity->type->value,
                    'provider' => $identity->provider,
                ],
            );

            return $contactIdentity;
        });
    }
}
