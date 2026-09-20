<?php

namespace App\Modules\Content\Domain\Brand;

enum BrandTokenKind: string
{
    case Color = 'color';
    case FontFamily = 'font_family';
    case FontSize = 'font_size';
    case FontWeight = 'font_weight';
    case Spacing = 'spacing';
    case Radius = 'radius';
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
}
