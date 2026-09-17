<?php

namespace App\Modules\DeliveryEngine\Application\Eligibility;

use App\Modules\DeliveryEngine\Application\Frequency\FrequencyEvaluationResult;
use App\Modules\DeliveryEngine\Application\Reputation\ReputationHealthEvaluationResult;
use App\Modules\DeliveryEngine\Domain\SenderAuthentication\AuthenticationDecision;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SafeSendingEligibilityRequest
{
    public function __construct(
        public string $workspaceId,
        public SuppressionAwareEligibilityRequest $suppressionAwareRequest,
        public ?AuthenticationDecision $senderAuthentication,
        public ?FrequencyEvaluationResult $frequencyEvaluation,
        public ?ReputationHealthEvaluationResult $reputationEvaluation,
        public DateTimeImmutable $evaluatedAt,
    ) {
        if (trim($workspaceId) === '') {
            throw new InvalidArgumentException('workspaceId must be non-blank.');
        }
    }
}
