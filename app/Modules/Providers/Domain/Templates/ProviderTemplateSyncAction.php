<?php

namespace App\Modules\Providers\Domain\Templates;

enum ProviderTemplateSyncAction: string
{
    case None = 'none';
    case Blocked = 'blocked';
    case Synchronize = 'synchronize';
    case UseFallback = 'use_fallback';
    case ReviewConflict = 'review_conflict';
}
