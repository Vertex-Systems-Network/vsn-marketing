<?php

namespace App\Modules\Journeys\Domain;

/** Persisted wait data returned to a workspace-scoped due-work consumer. */
final readonly class JourneyWaitRecord
{
    /** @param array<string, mixed>|null $predicate */
    public function __construct(
        public DurableJourneyWait $wait,
        public ?array $predicate,
    ) {}
}
