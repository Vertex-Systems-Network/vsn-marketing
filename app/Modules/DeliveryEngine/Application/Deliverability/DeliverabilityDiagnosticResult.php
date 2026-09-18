<?php

namespace App\Modules\DeliveryEngine\Application\Deliverability;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DeliverabilityDiagnosticResult
{
    public const STATUS_OBSERVED = 'observed';

    public const STATUS_REVIEW = 'review';

    public const STATUS_UNKNOWN = 'unknown';

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $evidenceIds
     * @param  list<string>  $signalKinds
     */
    public function __construct(
        public string $status,
        public string $workspaceId,
        public string $providerKey,
        public string $messagePurpose,
        public array $reasons,
        public array $evidenceIds,
        public array $signalKinds,
        public DateTimeImmutable $evaluatedAt,
    ) {
        if (! in_array($status, [
            self::STATUS_OBSERVED,
            self::STATUS_REVIEW,
            self::STATUS_UNKNOWN,
        ], true)) {
            throw new InvalidArgumentException('Unsupported deliverability diagnostic status.');
        }

        foreach ([
            'workspaceId' => $workspaceId,
            'providerKey' => $providerKey,
            'messagePurpose' => $messagePurpose,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must be non-blank.');
            }
        }
    }
}
