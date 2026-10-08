<?php

namespace App\Modules\Providers\Application\ConnectorFactory;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCandidateApproval;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCandidateCanaryPolicy;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCandidateValidationEvidence;

final class ConnectorCandidatePromotionGate
{
    public function decide(
        ConnectorCandidateValidationEvidence $evidence,
        ?ConnectorCandidateApproval $approval,
        ConnectorCandidateCanaryPolicy $policy,
        ConnectorCandidateApprovalVerifier $approvalVerifier,
    ): ConnectorCandidatePromotionDecision {
        if ($evidence->passed === false) {
            return new ConnectorCandidatePromotionDecision(false, 'candidate_only', 'validation_failed');
        }

        if ($approval === null
            || $approval->candidateId !== $evidence->candidateId
            || $approval->evidenceSha256 !== $evidence->evidenceSha256
            || $approvalVerifier->isAuthorized($approval) === false) {
            return new ConnectorCandidatePromotionDecision(false, 'candidate_only', 'independent_authorized_approval_required');
        }

        if ($policy->enabled === false) {
            return new ConnectorCandidatePromotionDecision(false, 'candidate_only', 'canary_disabled');
        }

        return new ConnectorCandidatePromotionDecision(
            true,
            'approved_for_canary',
            'explicit_approval_and_bounded_reversible_policy',
            $evidence->candidateId,
            $evidence->evidenceSha256,
        );
    }
}
