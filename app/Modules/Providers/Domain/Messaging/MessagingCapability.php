<?php

namespace App\Modules\Providers\Domain\Messaging;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MessagingCapability
{
    /** @param list<string> $requiredScopes */
    public function __construct(
        public MessagingChannel $channel,
        public string $providerKey,
        public CapabilitySupport $support,
        public ProviderReadinessStatus $readiness,
        public array $requiredScopes,
        public string $sourceUrl,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $freshUntil,
        public bool $sandboxOnly = true,
    ) {
        if (trim($providerKey) === '' || ! str_starts_with($sourceUrl, 'https://')) {
            throw new InvalidArgumentException('A provider key and HTTPS source provenance are required.');
        }
    }

    /** @param list<string> $grantedScopes */
    public function supportsOfflinePreparation(array $grantedScopes, DateTimeImmutable $at): bool
    {
        return $this->support === CapabilitySupport::Supported
            && in_array($this->readiness, [ProviderReadinessStatus::Ready, ProviderReadinessStatus::SandboxOnly], true)
            && $this->observedAt <= $at
            && $this->freshUntil !== null
            && $at < $this->freshUntil
            && count(array_diff($this->requiredScopes, $grantedScopes)) === 0;
    }

    public function permitsLiveDelivery(): bool
    {
        // Phase 13 adapter candidates do not bind authorized production credentials.
        return false;
    }
}
