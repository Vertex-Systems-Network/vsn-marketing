<?php

namespace App\Modules\Journeys;

use App\Modules\Journeys\Application\JourneyActionExecutor;
use App\Modules\Journeys\Application\JourneyRegistry;
use App\Modules\Journeys\Application\RejectUnconfiguredJourneyAction;
use App\Modules\Journeys\Domain\Contracts\JourneyNodeAttemptRepository;
use App\Modules\Journeys\Domain\Contracts\JourneyWaitRepository;
use App\Modules\Journeys\Domain\JourneyDefinitionException;
use App\Modules\Journeys\Domain\JourneyEnrollmentAdmissionPolicy;
use App\Modules\Journeys\Domain\JourneyGraphValidator;
use App\Modules\Journeys\Domain\JourneyNodeRegistry;
use App\Modules\Journeys\Infrastructure\Persistence\DatabaseJourneyNodeAttemptRepository;
use App\Modules\Journeys\Infrastructure\Persistence\DatabaseJourneyWaitRepository;
use Illuminate\Support\ServiceProvider;

final class JourneysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JourneyEnrollmentAdmissionPolicy::class, function ($app): JourneyEnrollmentAdmissionPolicy {
            $configuredLimit = $app['config']->get('journeys.max_active_enrollments_per_workspace');
            $limit = filter_var($configuredLimit, FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new JourneyDefinitionException('enrollment_budget_not_configured', '$.runtime_policy');
            }

            return new JourneyEnrollmentAdmissionPolicy($limit);
        });
        $this->app->singleton(JourneyGraphValidator::class);
        $this->app->singleton(JourneyNodeRegistry::class);
        $this->app->singleton(JourneyRegistry::class);
        $this->app->bind(JourneyWaitRepository::class, DatabaseJourneyWaitRepository::class);
        $this->app->bind(JourneyNodeAttemptRepository::class, DatabaseJourneyNodeAttemptRepository::class);
        $this->app->bind(JourneyActionExecutor::class, RejectUnconfiguredJourneyAction::class);
    }
}
