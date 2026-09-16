<?php

namespace App\Modules\Providers\Domain\SenderPolicy;

use DateTimeImmutable;

final readonly class MailboxProviderPolicyDecision
{
    public function __construct(
        public string $workspaceId,
        public string $providerKey,
        public ?string $policyKey,
        public ?string $policyVersion,
        public ProviderVolumeClassification $classification,
        public array $reasons,
        public array $requirements,
        public ?string $provenanceUrl,
        public DateTimeImmutable $decidedAt,
    ) {}
}
