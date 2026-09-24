<?php

namespace App\Modules\Publishing\Domain\Scheduling;

enum CampaignScheduleMissedReason: string
{
    case ApprovalInvalid = 'approval_invalid';
    case ExecutionDeadlineMissed = 'execution_deadline_missed';
}
