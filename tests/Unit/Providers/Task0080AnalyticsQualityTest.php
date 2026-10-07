<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Domain\Analytics\EngagementFact;
use App\Modules\Providers\Domain\Analytics\EngagementFactQuality;
use InvalidArgumentException;
use Tests\TestCase;

final class Task0080AnalyticsQualityTest extends TestCase
{
    public function test_duplicate_provider_events_fail_closed(): void
    {
        $fact = new EngagementFact('tenant-1', 'provider', 'clicks', 1, 'source-1', '2026-10-07T00:00:00Z');

        $this->expectException(InvalidArgumentException::class);

        EngagementFactQuality::validate([$fact, $fact]);
    }

    public function test_valid_delayed_events_are_accepted_when_verified_lineage_differs(): void
    {
        $facts = [
            new EngagementFact('tenant-1', 'provider', 'clicks', 1, 'source-1', '2026-10-07T00:00:00Z', false, '2026-10-07T00:04:00Z'),
            new EngagementFact('tenant-1', 'provider', 'clicks', 1, 'source-2', '2026-10-07T00:00:00Z', false, '2026-10-07T00:09:00Z'),
        ];

        EngagementFactQuality::validate($facts);

        $this->assertGreaterThan(
            new \DateTimeImmutable($facts[0]->observedAt),
            new \DateTimeImmutable($facts[0]->receivedAtValue()),
        );
        $this->assertFalse($facts[0]->definition()['cross_provider_equivalent']);
    }
}
