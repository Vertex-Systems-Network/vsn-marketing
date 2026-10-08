<?php

namespace App\Modules\Providers\Application\ConnectorFactory;

final readonly class ConnectorCandidatePromotionDecision
{
    public function __construct(
        public bool $allowed,
        public string $state,
        public string $reason,
        public ?string $candidateId = null,
        public ?string $evidenceSha256 = null,
    ) {}
}
