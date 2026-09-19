<?php

namespace App\Modules\Assets\Domain\Variant;

enum TransformationOperation: string
{
    case Resize = 'resize';
    case Crop = 'crop';
    case Convert = 'convert';
    case Quality = 'quality';
}
