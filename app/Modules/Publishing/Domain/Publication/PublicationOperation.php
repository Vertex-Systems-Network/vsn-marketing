<?php

namespace App\Modules\Publishing\Domain\Publication;

enum PublicationOperation: string
{
    case Retry = 'retry';
    case Edit = 'edit';
    case Delete = 'delete';

    public function providerCapabilityOperation(): string
    {
        return match ($this) {
            self::Retry => 'publication.create',
            self::Edit => 'publication.update',
            self::Delete => 'publication.delete',
        };
    }
}
