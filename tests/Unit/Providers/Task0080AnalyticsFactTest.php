<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Domain\Analytics\EngagementFact;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Task0080AnalyticsFactTest extends TestCase
{
    public function test_fact_preserves_lineage_and_unknown_totals(): void
    {
        $fact = new EngagementFact('tenant-a', 'provider-a', 'engagement', 4, 'https://provider.test/events/1', '2026-10-07T10:00:00+00:00');
        self::assertSame('https://provider.test/events/1', $fact->sourceLineage);
        self::assertTrue($fact->isUnknownTotal());
    }

    public function test_negative_values_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EngagementFact('tenant-a', 'provider-a', 'engagement', -1, 'https://provider.test/events/1', '2026-10-07T10:00:00+00:00', true);
    }
}
