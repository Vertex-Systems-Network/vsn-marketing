<?php

namespace App\Modules\Providers\Application\ConnectorFactory;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCandidateApproval;

interface ConnectorCandidateApprovalVerifier
{
    /**
     * Resolve approval authority from a trusted source independent of candidate artifacts.
     */
    public function isAuthorized(ConnectorCandidateApproval $approval): bool;
}
