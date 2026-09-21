<?php

namespace App\Modules\Publishing\Domain\Campaign;

use InvalidArgumentException;

final readonly class CampaignApprovalEvaluation
{
    private function __construct(
        public bool $valid,
        public ?string $decisionId,
        public ?CampaignApprovalInvalidReason $reason,
        public ?string $detail,
    ) {
        if ($this->valid && $this->reason !== null) {
            throw new InvalidArgumentException('A valid campaign approval evaluation cannot contain an invalidation reason.');
        }

        if (! $this->valid && $this->reason === null) {
            throw new InvalidArgumentException('An invalid campaign approval evaluation requires an invalidation reason.');
        }
    }

    public static function valid(?string $decisionId = null): self
    {
        return new self(
            valid: true,
            decisionId: $decisionId,
            reason: null,
            detail: null,
        );
    }

    public static function invalid(
        CampaignApprovalInvalidReason $reason,
        ?string $decisionId = null,
        ?string $detail = null,
    ): self {
        return new self(
            valid: false,
            decisionId: $decisionId,
            reason: $reason,
            detail: $detail,
        );
    }

    public function withDecisionId(string $decisionId): self
    {
        return $this->valid
            ? self::valid($decisionId)
            : self::invalid(
                reason: $this->reason,
                decisionId: $decisionId,
                detail: $this->detail,
            );
    }
}
