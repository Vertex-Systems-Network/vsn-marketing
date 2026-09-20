<?php

namespace App\Modules\Content\Domain\Authoring;

enum AuthoringTarget: string
{
    case Email = 'email';
    case Web = 'web';
}
