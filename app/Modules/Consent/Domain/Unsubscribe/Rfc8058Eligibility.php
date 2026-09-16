<?php

namespace App\Modules\Consent\Domain\Unsubscribe;

use App\Modules\DeliveryEngine\Domain\MessageIntentType;

final readonly class Rfc8058Eligibility
{
    /** @param list<string> $dkimCoveredHeaders */
    public function __construct(
        public MessageIntentType $messagePurpose,
        public bool $dkimSignatureValid,
        public array $dkimCoveredHeaders,
    ) {}

    public function eligible(): bool
    {
        return $this->messagePurpose === MessageIntentType::Marketing
            && $this->dkimSignatureValid
            && Rfc8058Headers::coveredByDkim($this->dkimCoveredHeaders);
    }
}
