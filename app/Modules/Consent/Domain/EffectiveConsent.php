<?php

namespace App\Modules\Consent\Domain;

use DateTimeImmutable;

final readonly class EffectiveConsent
{
    public function __construct(
        public EffectiveConsentStatus $status,
        public ?string $recordId = null,
        public ?DateTimeImmutable $occurredAt = null,
    ) {}

    public function isGranted(): bool
    {
        return $this->status === EffectiveConsentStatus::Granted;
    }
}
