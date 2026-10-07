<?php

namespace App\Modules\Providers\Domain\Community;

enum CommunityProposalState: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
