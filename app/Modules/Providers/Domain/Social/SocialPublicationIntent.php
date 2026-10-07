<?php

namespace App\Modules\Providers\Domain\Social;

use InvalidArgumentException;

final readonly class SocialPublicationIntent
{
    public function __construct(
        public string $workspaceId,
        public string $accountWorkspaceId,
        public SocialPlatform $platform,
        public SocialOperation $operation,
        public string $providerKey,
        public string $publicationAttemptId,
        public string $idempotencyKey,
        public bool $approvalValid,
        public bool $rateBudgetAvailable,
    ) {
        foreach ([
            'workspaceId' => $this->workspaceId,
            'accountWorkspaceId' => $this->accountWorkspaceId,
            'providerKey' => $this->providerKey,
            'publicationAttemptId' => $this->publicationAttemptId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Social publication {$field} must be non-empty.");
            }
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $this->idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Social publication idempotency key must be a SHA-256 digest.');
        }
    }
}
