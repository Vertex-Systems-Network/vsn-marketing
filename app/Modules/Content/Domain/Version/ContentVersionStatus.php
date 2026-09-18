<?php

namespace App\Modules\Content\Domain\Version;

enum ContentVersionStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Published = 'published';
    case ExecutionPinned = 'execution_pinned';

    public function isExecutionImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
