<?php

namespace App\Modules\Templates\Domain;

enum VersionStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Published = 'published';
    case ExecutionPinned = 'execution_pinned';

    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
