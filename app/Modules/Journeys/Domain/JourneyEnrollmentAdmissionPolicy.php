<?php

namespace App\Modules\Journeys\Domain;

final readonly class JourneyEnrollmentAdmissionPolicy
{
    public function __construct(public int $maxActiveEnrollmentsPerWorkspace)
    {
        if ($maxActiveEnrollmentsPerWorkspace < 1 || $maxActiveEnrollmentsPerWorkspace > 100000) {
            throw new JourneyDefinitionException('invalid_enrollment_budget', '$.runtime_policy');
        }
    }
}
