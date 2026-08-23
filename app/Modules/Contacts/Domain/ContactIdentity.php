<?php

namespace App\Modules\Contacts\Domain;

use DateTimeImmutable;

final readonly class ContactIdentity
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $contactId,
        public ContactIdentityType $type,
        public string $value,
        public string $normalizedValue,
        public ?string $provider,
        public ?string $providerReference,
        public ?DateTimeImmutable $verifiedAt = null,
    ) {}
}
