<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class RecipientIdentity
{
    public function __construct(
        public string $contactId,
        public string $contactIdentityId,
        public string $type,
        public string $value,
        public string $normalizedValue,
        public ?string $provider,
        public ?string $providerReference,
        public ?string $verifiedAt,
    ) {}
}
