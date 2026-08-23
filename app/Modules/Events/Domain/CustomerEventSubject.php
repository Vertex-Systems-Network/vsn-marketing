<?php

namespace App\Modules\Events\Domain;

final readonly class CustomerEventSubject
{
    public function __construct(
        public string $contactId,
        public ?string $contactIdentityId,
        public ?string $brandId,
    ) {}
}
