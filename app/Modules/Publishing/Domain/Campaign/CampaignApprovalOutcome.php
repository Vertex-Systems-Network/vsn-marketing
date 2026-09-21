<?php

namespace App\Modules\Publishing\Domain\Campaign;

enum CampaignApprovalOutcome: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
}
