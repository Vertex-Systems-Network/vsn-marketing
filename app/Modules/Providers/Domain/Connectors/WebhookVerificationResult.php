<?php

namespace App\Modules\Providers\Domain\Connectors;

final readonly class WebhookVerificationResult
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public WebhookVerificationStatus $status,
        public string $strategy,
        public ?string $deduplicationKey = null,
        public ?string $sourceEventId = null,
        public array $evidence = [],
    ) {}

    public function accepts(bool $verificationRequired): bool
    {
        return match ($this->status) {
            WebhookVerificationStatus::Verified => true,
            WebhookVerificationStatus::NotRequired => ! $verificationRequired,
            WebhookVerificationStatus::Rejected,
            WebhookVerificationStatus::Unsupported => false,
        };
    }
}
