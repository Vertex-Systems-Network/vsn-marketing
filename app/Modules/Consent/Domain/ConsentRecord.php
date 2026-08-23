<?php

namespace App\Modules\Consent\Domain;

use DateTimeImmutable;

final readonly class ConsentRecord
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $contactId,
        public string $channel,
        public string $purpose,
        public string $source,
        public ConsentDecision $decision,
        public DateTimeImmutable $occurredAt,
    ) {}
}
