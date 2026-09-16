<?php

namespace App\Modules\DeliveryEngine\Application\SuppressionSync;

use DateTimeImmutable;

final readonly class SuppressionSynchronizationResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public string $operationKey,
        public string $workspaceId,
        public string $suppressionRecordId,
        public string $providerKey,
        public SuppressionSynchronizationOutcome $providerOutcome,
        public bool $reconciliationRequired,
        public bool $internalSuppressionActive,
        public bool $restoresEligibility,
        public array $reasons,
        public DateTimeImmutable $observedAt,
        public ?string $providerReference,
    ) {}
}
