<?php

namespace App\Modules\Analytics;

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Application\AnalyticsQuality;
use App\Modules\Analytics\Application\ProviderEngagementAnalytics;
use App\Modules\Analytics\Domain\AnalyticsAccess;
use App\Modules\Analytics\Domain\AnalyticsPrivacy;
use App\Modules\Analytics\Domain\AnalyticsSourceVerifier;
use App\Modules\Analytics\Domain\RevenueExperimentVerifier;
use App\Modules\Analytics\Infrastructure\CanonicalAnalyticsAccess;
use App\Modules\Analytics\Infrastructure\ConsentAnalyticsPrivacy;
use App\Modules\Analytics\Presentation\Console\GenerateDueAnalyticsReports;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class AnalyticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateDueAnalyticsReports::class]);
        }
    }

    public function register(): void
    {
        $this->app->bind(AnalyticsFacts::class, fn ($app) => new AnalyticsFacts(
            $app->make(DatabaseManager::class), $app->make(AnalyticsAccess::class),
            $app->make(AnalyticsPrivacy::class), $app->make(Clock::class),
            $app->make(AuditRecorder::class), (string) $app['config']->get('app.key'),
            $app->bound(RevenueExperimentVerifier::class) ? $app->make(RevenueExperimentVerifier::class) : null,
        ));
        $this->app->bind(AnalyticsQuality::class, fn ($app) => new AnalyticsQuality(
            $app->make(AnalyticsFacts::class), $app->make(AnalyticsPrivacy::class),
            $app->make(DatabaseManager::class), $app->make(Clock::class), $app->make(AuditRecorder::class),
            $app->bound(AnalyticsSourceVerifier::class) ? $app->make(AnalyticsSourceVerifier::class) : null,
        ));
        $this->app->bind(ProviderEngagementAnalytics::class, fn ($app) => new ProviderEngagementAnalytics(
            $app->make(AnalyticsFacts::class), $app->make(AnalyticsPrivacy::class),
            $app->make(DatabaseManager::class), $app->make(Clock::class), $app->make(AuditRecorder::class),
        ));
        $this->app->bind(AnalyticsAccess::class, CanonicalAnalyticsAccess::class);
        $this->app->bind(AnalyticsPrivacy::class, ConsentAnalyticsPrivacy::class);
    }
}
