<?php

namespace App\Modules\Consent\Domain\Unsubscribe;

use InvalidArgumentException;

final readonly class UnsubscribeScope
{
    public function __construct(
        public string $workspaceId,
        public string $contactId,
        public string $channel,
        public string $purpose,
        public string $scopeType,
        public ?string $scopeKey,
    ) {
        foreach ([
            'workspaceId' => $workspaceId,
            'contactId' => $contactId,
            'channel' => $channel,
            'purpose' => $purpose,
            'scopeType' => $scopeType,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field.' must not be blank.');
            }
        }

        if (mb_strlen($channel) > 64 || mb_strlen($scopeType) > 64) {
            throw new InvalidArgumentException('Unsubscribe scope exceeds storage limits.');
        }

        if (mb_strlen($purpose) > 120 || ($scopeKey !== null && mb_strlen($scopeKey) > 191)) {
            throw new InvalidArgumentException('Unsubscribe scope exceeds storage limits.');
        }
    }

    public function identityKey(): string
    {
        return implode(':', [
            $this->workspaceId,
            $this->contactId,
            $this->channel,
            $this->purpose,
            $this->scopeType,
            $this->scopeKey ?? '*',
        ]);
    }
}
