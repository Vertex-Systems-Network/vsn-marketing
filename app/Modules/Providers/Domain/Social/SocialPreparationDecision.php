<?php

namespace App\Modules\Providers\Domain\Social;

use InvalidArgumentException;

final readonly class SocialPreparationDecision
{
    /** @param list<string> $reasons */
    public function __construct(
        public bool $offlinePreparationAllowed,
        public bool $livePublicationAllowed,
        public array $reasons,
    ) {
        if ($this->livePublicationAllowed) {
            throw new InvalidArgumentException('Phase 13 social adapters cannot authorize live provider publication.');
        }

        if ($this->offlinePreparationAllowed && $this->reasons !== []) {
            throw new InvalidArgumentException('Allowed social preparation cannot carry denial reasons.');
        }
    }
}
