<?php

namespace App\Modules\DeliveryEngine\Application\Eligibility;

use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use DateTimeImmutable;

final readonly class SafeSendingEligibilityResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public EligibilityOutcome $outcome,
        public MessageIntentType $messagePurpose,
        public ?string $providerKey,
        public bool $suppressionAuthorityApplied,
        public bool $senderIdentityReady,
        public bool $frequencyAllowed,
        public bool $reputationHealthy,
        public array $reasons,
        public DateTimeImmutable $evaluatedAt,
    ) {}
}
