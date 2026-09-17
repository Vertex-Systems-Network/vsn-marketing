<?php

namespace App\Modules\DeliveryEngine\Application\Reputation;

use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use DateTimeImmutable;

final readonly class ReputationHealthEvaluationResult
{
    /** @param list<string> $reasons
     *  @param list<string> $evidenceIds
     */
    public function __construct(
        public EligibilityOutcome $outcome,
        public array $reasons,
        public DateTimeImmutable $evaluatedAt,
        public string $providerKey,
        public array $evidenceIds,
    ) {}
}
