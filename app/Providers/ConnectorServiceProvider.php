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
use App\Connectors\Dedup\RedisDedupStore;
use App\Connectors\Dedup\DatabaseDedupStore;

class ConnectorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(ErrorNormalizerInterface::class, BaseErrorNormalizer::class);
        $this->app->singleton(WebhookVerifierInterface::class, GenericWebhookVerifier::class);
        $this->app->singleton(QuotaSignalInterface::class, QuotaSignalParser::class);
        $this->app->singleton(ReconciliationInterface::class, DefaultReconciler::class);

        // Dedup store selection based on config
        $store = config('connectors.dedup_store', 'in_memory');

        switch ($store) {
            case 'redis':
                $this->app->singleton(DedupStoreInterface::class, RedisDedupStore::class);
                break;
            case 'database':
                $this->app->singleton(DedupStoreInterface::class, DatabaseDedupStore::class);
                break;
            case 'in_memory':
            default:
                $this->app->singleton(DedupStoreInterface::class, InMemoryDedupStore::class);
                break;
        }
    }

    public function boot()
    {
        // Nothing to boot yet
    }
}
