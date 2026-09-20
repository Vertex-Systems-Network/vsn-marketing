<?php

namespace App\Modules\Content\Domain\Brand;

enum BrandVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
