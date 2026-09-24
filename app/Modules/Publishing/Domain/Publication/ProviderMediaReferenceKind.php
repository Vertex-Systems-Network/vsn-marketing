<?php

namespace App\Modules\Publishing\Domain\Publication;

enum ProviderMediaReferenceKind: string
{
    case Upload = 'upload';
    case Container = 'container';
    case Media = 'media';
}
