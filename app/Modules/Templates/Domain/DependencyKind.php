<?php

namespace App\Modules\Templates\Domain;

enum DependencyKind: string
{
    case TemplateVersion = 'template_version';
    case ComponentVersion = 'component_version';
}
