<?php

return [
    // Deliberately unset by default: configure an evidence-backed workspace budget before enrolling.
    'max_active_enrollments_per_workspace' => env('JOURNEY_MAX_ACTIVE_ENROLLMENTS_PER_WORKSPACE'),
    // Conservative backpressure until a production workspace budget is approved.
    'max_workspace_concurrent_attempts' => env('JOURNEY_MAX_WORKSPACE_CONCURRENT_ATTEMPTS', 1),
];
