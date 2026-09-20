<?php

namespace App\Modules\Content\Domain\Authoring;

enum AuthoringMode: string
{
    case Visual = 'visual';
    case SafeMarkupImport = 'safe_markup_import';
    case AiAssisted = 'ai_assisted';

    public function requiresSanitizationEvidence(): bool
    {
        return $this === self::SafeMarkupImport;
    }
}
