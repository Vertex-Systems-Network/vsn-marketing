<?php

namespace App\Modules\Publishing\Domain\Publication;

enum ProviderMediaAssetReferenceKind: string
{
    case Original = 'original';
    case Variant = 'variant';
}
