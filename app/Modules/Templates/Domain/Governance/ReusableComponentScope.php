<?php

namespace App\Modules\Templates\Domain\Governance;

enum ReusableComponentScope: string
{
    case Local = 'local';
    case Global = 'global';
}
