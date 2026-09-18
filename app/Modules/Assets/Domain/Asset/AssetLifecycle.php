<?php

namespace App\Modules\Assets\Domain\Asset;

enum AssetLifecycle: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Active || $next === self::Archived,
            self::Active => $next === self::Archived,
            self::Archived => false,
        };
    }
}
