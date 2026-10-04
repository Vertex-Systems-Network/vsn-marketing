<?php

namespace App\Modules\Providers\Domain\Messaging;

use App\Modules\DeliveryEngine\Application\Eligibility\SuppressionAwareEligibilityRequest;
use InvalidArgumentException;

final readonly class MessagingIntent
{
    public function __construct(
        public string $workspaceId,
        public string $brandId,
        public string $accountWorkspaceId,
        public string $providerKey,
        public MessagingChannel $channel,
        public string $recipientIdentityType,
        public string $recipientReference,
        public string $idempotencyKey,
        public bool $channelPurposeAuthorized,
        public bool $providerOptOutApplies,
        public bool $accountApproved,
        public bool $rateBudgetAvailable,
        public SuppressionAwareEligibilityRequest $eligibility,
    ) {
        foreach ([$workspaceId, $brandId, $accountWorkspaceId, $providerKey, $recipientReference, $idempotencyKey] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Messaging scope, recipient and idempotency references must be non-blank.');
            }
        }
    }
}
