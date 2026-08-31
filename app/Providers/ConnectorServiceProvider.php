<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Connectors\Contracts\ErrorNormalizerInterface;
use App\Connectors\ErrorNormalizer\BaseErrorNormalizer;
use App\Connectors\Contracts\WebhookVerifierInterface;
use App\Connectors\Webhook\GenericWebhookVerifier;
use App\Connectors\Contracts\QuotaSignalInterface;
use App\Connectors\Quota\QuotaSignalParser;
use App\Connectors\Contracts\ReconciliationInterface;
use App\Connectors\Reconciliation\DefaultReconciler;
use App\Connectors\Dedup\DedupStoreInterface;
use App\Connectors\Dedup\InMemoryDedupStore;

class ConnectorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(ErrorNormalizerInterface::class, BaseErrorNormalizer::class);
        $this->app->singleton(WebhookVerifierInterface::class, GenericWebhookVerifier::class);
        $this->app->singleton(QuotaSignalInterface::class, QuotaSignalParser::class);
        $this->app->singleton(ReconciliationInterface::class, DefaultReconciler::class);
        $this->app->singleton(DedupStoreInterface::class, InMemoryDedupStore::class);
    }

    public function boot()
    {
        // Nothing to boot yet
    }
}
