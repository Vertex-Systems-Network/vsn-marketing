<?php

namespace App\Modules\DeliveryEngine\Domain\Frequency;

use App\Modules\DeliveryEngine\Domain\MessageIntentType;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FrequencyPolicy
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public MessageIntentType $messagePurpose,
        public string $recipientScope,
        public int $windowSeconds,
        public int $maxMessages,
        public string $version,
        public DateTimeImmutable $effectiveAt,
    ) {
        foreach ([
            'id' => $id,
            'workspaceId' => $workspaceId,
            'recipientScope' => $recipientScope,
            'version' => $version,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must be non-blank.');
            }
        }

        if ($windowSeconds <= 0) {
            throw new InvalidArgumentException('windowSeconds must be greater than zero.');
        }

        if ($maxMessages <= 0) {
            throw new InvalidArgumentException('maxMessages must be greater than zero.');
        }
    }

    /** @return array{start: DateTimeImmutable, end: DateTimeImmutable} */
    public function windowFor(DateTimeImmutable $at): array
    {
        $startTimestamp = intdiv($at->getTimestamp(), $this->windowSeconds) * $this->windowSeconds;
        $start = $at->setTimestamp($startTimestamp);

        return [
            'start' => $start,
            'end' => $start->modify('+'.$this->windowSeconds.' seconds'),
        ];
    }
}
