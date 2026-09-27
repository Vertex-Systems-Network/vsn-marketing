<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Journeys\Domain\JourneyActionGate;

/** Enforces the runtime gate immediately before any registered action dispatch. */
final readonly class DispatchJourneyAction
{
    public function __construct(private JourneyActionGate $gate = new JourneyActionGate) {}

    /** @param array<string, bool> $checks */
    public function handle(array $checks, callable $dispatch): mixed
    {
        $this->gate->assertAllowed($checks);

        return $dispatch();
    }
}
