<?php

namespace App\Modules\Analytics\Infrastructure;

use App\Modules\Analytics\Domain\AnalyticsPrivacy;
use App\Modules\Consent\Application\GetEffectiveConsent;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;

final readonly class ConsentAnalyticsPrivacy implements AnalyticsPrivacy
{
    public function __construct(private GetEffectiveConsent $consent, private Repository $config) {}

    public function retentionDays(TenantContext $actor): ?int
    {
        $days = $this->config->get('analytics.retention_days');

        return $this->config->get('analytics.purpose_approved') === true && is_int($days) && $days >= 1 && $days <= 365
            ? $days : null;
    }

    public function permits(TenantContext $actor, string $contactId, DateTimeImmutable $occurredAt): bool
    {
        $consent = $this->consent->handle($actor, $contactId, 'analytics', 'measurement');

        return $this->retentionDays($actor) !== null && $consent->isGranted()
            && $consent->occurredAt !== null && $consent->occurredAt <= $occurredAt;
    }
}
