<?php

namespace App\Modules\Providers\Domain\Messaging;

enum MessagingChannel: string
{
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Rcs = 'rcs';
    case Push = 'push';
    case InApp = 'in_app';

    public function recipientIdentity(): string
    {
        return match ($this) {
            self::Sms, self::WhatsApp, self::Rcs => 'phone',
            self::Push => 'device_token',
            self::InApp => 'application_user',
        };
    }

    public function operation(): string
    {
        return $this->value.'.send';
    }
}
