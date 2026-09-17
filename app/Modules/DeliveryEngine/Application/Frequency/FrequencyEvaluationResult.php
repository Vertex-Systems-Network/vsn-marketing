<?php

namespace App\Modules\DeliveryEngine\Application\Frequency;

use App\Modules\DeliveryEngine\Domain\Eligibility\EligibilityOutcome;
use DateTimeImmutable;

final readonly class FrequencyEvaluationResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public EligibilityOutcome $outcome,
        public array $reasons,
        public DateTimeImmutable $evaluatedAt,
        public bool $replay,
        public ?int $currentCount,
        public ?int $nextCount,
        public ?DateTimeImmutable $windowStart,
        public ?DateTimeImmutable $windowEnd,
    ) {}
}
