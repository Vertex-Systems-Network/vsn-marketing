<?php

namespace App\Modules\Journeys\Application;

use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyEnrollmentAdmissionPolicy;
use App\Modules\Journeys\Domain\JourneyExecutionIdentity;
use App\Modules\Journeys\Domain\JourneyReentryPolicy;

final class JourneyEnrollmentGuard
{
    public function assertReentryAllowed(
        JourneyReentryPolicy $policy,
        int $priorEnrollmentCount,
        ?string $latestStatus = null,
        ?int $maximumEnrollments = null,
    ): void {
        if (! $policy->allows($priorEnrollmentCount, $latestStatus, $maximumEnrollments)) {
            throw new JourneyDefinitionException('reentry_not_allowed', '$.enrollment');
        }
    }

    public function assertWorkspaceAdmissionAllowed(int $activeEnrollmentCount, JourneyEnrollmentAdmissionPolicy $policy): void
    {
        if ($activeEnrollmentCount < 0 || $activeEnrollmentCount >= $policy->maxActiveEnrollmentsPerWorkspace) {
            throw new JourneyDefinitionException('workspace_enrollment_budget_exceeded', '$.enrollment');
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function key(string $workspaceId, string $journeyVersionId, string $subjectId, array $event): string
    {
        $eventId = $event['event_id'] ?? null;
        if (! is_string($eventId) || $eventId === '') {
            throw new \InvalidArgumentException('canonical event_id is required');
        }

        return JourneyExecutionIdentity::for($workspaceId, $journeyVersionId, $subjectId, $eventId);
    }
}
