<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryPriorityClass: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';

    public static function fromIntent(MessageIntentType $intent): self
    {
        return match ($intent) {
            MessageIntentType::Transactional => self::Transactional,
            MessageIntentType::Marketing => self::Marketing,
        };
    }
}
