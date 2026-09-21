<?php

namespace App\Modules\Publishing\Application\Governance;

use App\Modules\Publishing\Domain\Campaign\CampaignApprovalEvaluation;

final readonly class CampaignApprovalEvaluationProxy
{
    public function __construct(
        private CampaignApprovalEvaluation $evaluation,
        private string $decisionId,
    ) {}

    public function value(): CampaignApprovalEvaluation
    {
        if ($this->evaluation->valid) {
            return CampaignApprovalEvaluation::valid($this->decisionId);
        }

        return CampaignApprovalEvaluation::invalid(
            reason: $this->evaluation->reason,
            decisionId: $this->decisionId,
            detail: $this->evaluation->detail,
        );
    }
}
