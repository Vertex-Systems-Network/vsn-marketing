<?php

namespace App\Modules\Templates\Domain;

enum DefinitionKind: string
{
    case Template = 'template';
    case Component = 'component';
}
