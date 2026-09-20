<?php

namespace App\Modules\Providers\Infrastructure\TemplateSync;

use App\Modules\Providers\Application\TemplateSync\ProviderTemplateSyncPlan;
use InvalidArgumentException;

final class InMemoryProviderTemplateSyncLedger
{
    /** @var array<string, ProviderTemplateSyncPlan> */
    private array $plans = [];

    public function claim(ProviderTemplateSyncPlan $plan): ProviderTemplateSyncPlan
    {
        $key = $plan->workspaceId.'|'.$plan->idempotencyKey;
        $existing = $this->plans[$key] ?? null;

        if ($existing !== null) {
            if ($existing->requestIdentity !== $plan->requestIdentity) {
                throw new InvalidArgumentException('Provider template sync idempotency key conflicts with different request evidence.');
            }

            return $existing;
        }

        $this->plans[$key] = $plan;

        return $plan;
    }

    public function find(string $workspaceId, string $idempotencyKey): ?ProviderTemplateSyncPlan
    {
        if (trim($workspaceId) === '' || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Provider template sync ledger lookup requires workspace and idempotency key.');
        }

        return $this->plans[$workspaceId.'|'.$idempotencyKey] ?? null;
    }
}
