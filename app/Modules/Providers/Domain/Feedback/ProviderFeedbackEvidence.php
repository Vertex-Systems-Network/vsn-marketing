<?php

namespace App\Modules\Providers\Domain\Feedback;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final readonly class ProviderFeedbackEvidence
{
    public string $evidenceFingerprint;

    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $contactId,
        public string $providerKey,
        public ProviderFeedbackType $type,
        public ProviderFeedbackClassification $classification,
        public string $replayKey,
        public string $sourceVersion,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $freshUntil,
        public bool $trustedSource,
        public array $redactedEvidence,
    ) {
        foreach ([
            'id' => $id,
            'workspaceId' => $workspaceId,
            'contactId' => $contactId,
            'providerKey' => $providerKey,
            'replayKey' => $replayKey,
            'sourceVersion' => $sourceVersion,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must not be blank.');
            }
        }

        if (mb_strlen($providerKey) > 120 || mb_strlen($sourceVersion) > 120 || mb_strlen($replayKey) > 191) {
            throw new InvalidArgumentException('Provider feedback exceeds storage limits.');
        }

        if ($freshUntil !== null && $freshUntil < $observedAt) {
            throw new InvalidArgumentException('freshUntil must not precede observedAt.');
        }

        $this->assertClassificationMatchesType();
        self::assertRedacted($redactedEvidence);
        $this->evidenceFingerprint = self::fingerprint($redactedEvidence);
    }

    /** @throws JsonException */
    private static function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertClassificationMatchesType(): void
    {
        $valid = match ($this->type) {
            ProviderFeedbackType::Bounce => in_array(
                $this->classification,
                [ProviderFeedbackClassification::PermanentBounce, ProviderFeedbackClassification::TransientBounce],
                true,
            ),
            ProviderFeedbackType::Complaint => $this->classification === ProviderFeedbackClassification::Complaint,
            ProviderFeedbackType::Unsubscribe => $this->classification === ProviderFeedbackClassification::AcceptedUnsubscribe,
        };

        if (! $valid) {
            throw new InvalidArgumentException('Provider feedback classification does not match feedback type.');
        }
    }

    private static function assertRedacted(array $payload, string $path = 'redactedEvidence'): void
    {
        $forbidden = [
            'password', 'secret', 'private_key', 'api_key', 'access_token', 'refresh_token',
            'credential', 'authorization', 'cookie', 'raw_email', 'recipient_email', 'email_address',
        ];

        foreach ($payload as $key => $value) {
            $current = $path.'.'.(string) $key;
            $normalized = strtolower((string) $key);
            foreach ($forbidden as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    throw new InvalidArgumentException('Provider feedback evidence contains secret or direct-PII material at '.$current.'.');
                }
            }

            if (is_array($value)) {
                self::assertRedacted($value, $current);
            } elseif (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Provider feedback evidence must be JSON-safe at '.$current.'.');
            }
        }
    }
}
