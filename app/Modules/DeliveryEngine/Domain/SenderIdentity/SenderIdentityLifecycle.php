<?php

namespace App\Modules\DeliveryEngine\Domain\SenderIdentity;

enum SenderIdentityLifecycle: string
{
    case Registered = 'registered';
    case Managed = 'managed';
    case Suspended = 'suspended';
    case Retired = 'retired';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return match ($this) {
            self::Registered => in_array($next, [self::Managed, self::Suspended, self::Retired], true),
            self::Managed => in_array($next, [self::Suspended, self::Retired], true),
            self::Suspended => in_array($next, [self::Managed, self::Retired], true),
            self::Retired => false,
        };
    }
}
