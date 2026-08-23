<?php

namespace App\Modules\Core\Domain\Idempotency;

use InvalidArgumentException;

final readonly class IdempotencyClaim
{
    public const string ACQUIRED = 'acquired';

    public const string COMPLETED = 'completed';

    public const string IN_PROGRESS = 'in_progress';

    public function __construct(
        public string $state,
        public ?array $result = null,
    ) {
        if (! in_array($this->state, [self::ACQUIRED, self::COMPLETED, self::IN_PROGRESS], true)) {
            throw new InvalidArgumentException("Invalid idempotency claim state: {$this->state}");
        }
    }
}
