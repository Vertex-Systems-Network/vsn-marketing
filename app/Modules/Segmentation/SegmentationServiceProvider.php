<?php

namespace App\Modules\Segmentation;

use App\Modules\Segmentation\Domain\SegmentFieldRegistry;
use App\Modules\Segmentation\Domain\SegmentValidator;
use Illuminate\Support\ServiceProvider;

final class SegmentationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SegmentFieldRegistry::class);
        $this->app->singleton(SegmentValidator::class);
    }
}
