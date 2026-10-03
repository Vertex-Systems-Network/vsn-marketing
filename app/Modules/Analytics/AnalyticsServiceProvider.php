<?php

namespace App\Modules\Analytics;

use App\Modules\Analytics\Application\AnalyticsFacts;
use App\Modules\Analytics\Domain\AnalyticsAccess;
use App\Modules\Analytics\Domain\AnalyticsPrivacy;
use App\Modules\Analytics\Infrastructure\CanonicalAnalyticsAccess;
use App\Modules\Analytics\Infrastructure\ConsentAnalyticsPrivacy;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Core\Domain\Contracts\Clock;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AnalyticsFacts::class, fn ($app) => new AnalyticsFacts(
            $app->make(DatabaseManager::class), $app->make(AnalyticsAccess::class),
            $app->make(AnalyticsPrivacy::class), $app->make(Clock::class),
            $app->make(AuditRecorder::class), (string) $app['config']->get('app.key'),
        ));
        $this->app->bind(AnalyticsAccess::class, CanonicalAnalyticsAccess::class);
        $this->app->bind(AnalyticsPrivacy::class, ConsentAnalyticsPrivacy::class);
    }
}
