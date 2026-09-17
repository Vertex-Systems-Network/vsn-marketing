<?php

namespace App\Modules\DeliveryEngine\Domain\Frequency;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FrequencyCounterSnapshot
{
    /** @param list<string> $countedOperationKeys */
    public function __construct(
        public string $workspaceId,
        public string $policyId,
        public string $recipientScope,
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $windowEnd,
        public int $count,
        public array $countedOperationKeys,
        public DateTimeImmutable $observedAt,
    ) {
        foreach ([
            'workspaceId' => $workspaceId,
            'policyId' => $policyId,
            'recipientScope' => $recipientScope,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must be non-blank.');
            }
        }

        if ($windowEnd <= $windowStart) {
            throw new InvalidArgumentException('windowEnd must be after windowStart.');
        }

        if ($count < 0) {
            throw new InvalidArgumentException('count must be zero or greater.');
        }

        foreach ($countedOperationKeys as $operationKey) {
            if (! is_string($operationKey) || trim($operationKey) === '') {
                throw new InvalidArgumentException('countedOperationKeys must contain only non-blank strings.');
            }
        }

        if (count(array_unique($countedOperationKeys)) !== count($countedOperationKeys)) {
            throw new InvalidArgumentException('countedOperationKeys must be unique.');
        }
    }

    public function hasCounted(string $operationKey): bool
    {
        return in_array($operationKey, $this->countedOperationKeys, true);
    }
}
