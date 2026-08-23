<?php

namespace App\Modules\Contacts\Domain;

final readonly class NormalizedContactIdentity
{
    public function __construct(
        public ContactIdentityType $type,
        public string $value,
        public string $normalizedValue,
        public ?string $provider,
        public ?string $providerReference,
    ) {}
}
