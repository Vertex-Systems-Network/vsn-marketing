<?php

namespace App\Modules\Providers\Domain\Social;

use App\Modules\Providers\Domain\CapabilitySupport;
use App\Modules\Providers\Domain\ProviderReadinessStatus;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SocialCapability
{
    /** @param list<string> $requiredScopes @param list<string> $requiredRoles @param list<string> $constraints */
    public function __construct(
        public SocialPlatform $platform,
        public SocialOperation $operation,
        public string $providerKey,
        public CapabilitySupport $support,
        public ProviderReadinessStatus $readiness,
        public array $requiredScopes,
        public array $requiredRoles,
        public array $constraints,
        public string $sourceUrl,
        public DateTimeImmutable $observedAt,
        public ?DateTimeImmutable $freshUntil,
        public bool $liveEnabled = false,
    ) {
        if (trim($this->providerKey) === '' || ! str_starts_with($this->sourceUrl, 'https://')) {
            throw new InvalidArgumentException('Social capability requires provider key and HTTPS provenance.');
        }
        foreach ([$this->requiredScopes, $this->requiredRoles, $this->constraints] as $values) {
            if (! array_is_list($values) || array_filter($values, static fn (mixed $v): bool => ! is_string($v) || trim($v) === '') !== []) {
                throw new InvalidArgumentException('Social capability lists must contain non-empty strings.');
            }
        }
        if ($this->liveEnabled) {
            throw new InvalidArgumentException('Task 0077 candidates cannot enable live provider publication.');
        }
    }

    /** @param list<string> $grantedScopes @param list<string> $roles */
    public function isUsableOffline(array $grantedScopes, array $roles, DateTimeImmutable $at): bool
    {
        return $this->support === CapabilitySupport::Supported
            && $this->readiness === ProviderReadinessStatus::SandboxOnly
            && $this->observedAt <= $at
            && $this->freshUntil !== null && $at < $this->freshUntil
            && array_diff($this->requiredScopes, $grantedScopes) === []
            && array_diff($this->requiredRoles, $roles) === [];
    }
}
