<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryChannel: string
{
    case Email = 'email';

    public function contactIdentityType(): string
    {
        return match ($this) {
            self::Email => 'email',
        };
    }

    public function providerOperation(): string
    {
        return match ($this) {
            self::Email => 'email.send',
        };
    }
}
