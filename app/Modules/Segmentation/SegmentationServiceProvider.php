<?php

namespace App\Modules\Segmentation;

use App\Modules\Segmentation\Domain\Contracts\SegmentProposalProvider;
use App\Modules\Segmentation\Domain\SegmentFieldRegistry;
use App\Modules\Segmentation\Domain\SegmentValidator;
use App\Modules\Segmentation\Infrastructure\AI\UnavailableSegmentProposalProvider;
use Illuminate\Support\ServiceProvider;

final class SegmentationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SegmentFieldRegistry::class);
        $this->app->singleton(SegmentValidator::class);
        $this->app->singleton(SegmentProposalProvider::class, UnavailableSegmentProposalProvider::class);
    }
}
