<?php

namespace App\Modules\DeliveryEngine\Application\SuppressionSync;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SuppressionSynchronizationRequest
{
    public function __construct(
        public string $operationKey,
        public string $workspaceId,
        public string $suppressionRecordId,
        public string $providerKey,
        public SuppressionSynchronizationOutcome $providerOutcome,
        public DateTimeImmutable $observedAt,
        public ?string $providerReference = null,
        public array $publicEvidence = [],
    ) {
        foreach ([
            'operationKey' => $operationKey,
            'workspaceId' => $workspaceId,
            'suppressionRecordId' => $suppressionRecordId,
            'providerKey' => $providerKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must not be blank.');
            }
        }

        if (mb_strlen($operationKey) > 191 || mb_strlen($providerKey) > 120) {
            throw new InvalidArgumentException('Suppression synchronization request exceeds storage limits.');
        }

        if ($providerReference !== null && mb_strlen($providerReference) > 191) {
            throw new InvalidArgumentException('providerReference exceeds storage limit.');
        }

        self::assertPublicEvidence($publicEvidence);
    }

    private static function assertPublicEvidence(array $payload, string $path = 'publicEvidence'): void
    {
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            foreach (['secret', 'password', 'private_key', 'api_key', 'access_token', 'credential', 'authorization', 'cookie', 'email_address', 'recipient_email'] as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    throw new InvalidArgumentException('Suppression synchronization evidence contains restricted material at '.$path.'.'.$key.'.');
                }
            }

            if (is_array($value)) {
                self::assertPublicEvidence($value, $path.'.'.$key);
            } elseif (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Suppression synchronization evidence must be JSON-safe.');
            }
        }
    }
}
