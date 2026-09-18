<?php

namespace App\Modules\Content\Domain\Document;

enum CanonicalNodeType: string
{
    case Root = 'root';
    case Section = 'section';
    case Text = 'text';
    case Image = 'image';
    case Button = 'button';
    case ComponentReference = 'component_reference';
    case Slot = 'slot';

    public function acceptsChildren(): bool
    {
        return match ($this) {
            self::Root, self::Section => true,
            default => false,
        };
    }
}
