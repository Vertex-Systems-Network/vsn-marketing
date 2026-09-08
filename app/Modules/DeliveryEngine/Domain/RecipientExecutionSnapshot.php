<?php

namespace App\Modules\DeliveryEngine\Domain;

final readonly class RecipientExecutionSnapshot
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $messageSnapshotId,
        public string $contactId,
        public string $contactIdentityId,
        public DeliveryChannel $channel,
        public string $destination,
        public string $normalizedDestination,
        public ?string $identityProvider,
        public ?string $identityProviderReference,
        public ?string $identityVerifiedAt,
        public string $contentHash,
    ) {}
}
