<?php

namespace App\Modules\Content\Domain\Document;

enum ContentLifecycle: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Active, self::Archived], true),
            self::Active => $next === self::Archived,
            self::Archived => false,
        };
    }
}
