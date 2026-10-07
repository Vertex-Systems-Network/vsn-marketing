<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Domain\Analytics\EngagementFact;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Task0080AnalyticsFactTest extends TestCase
{
    public function test_fact_preserves_source_specific_definition_lineage_timestamps_and_unknown_totals(): void
    {
        $fact = new EngagementFact(
            'tenant-a', 'provider-a', 'engagement', 4, 'https://provider.test/events/1',
            '2026-10-07T10:00:00+00:00', false, '2026-10-07T10:03:00+00:00',
        );

        self::assertSame('https://provider.test/events/1', $fact->sourceLineage);
        self::assertSame('2026-10-07T10:03:00+00:00', $fact->receivedAtValue());
        self::assertTrue($fact->isUnknownTotal());
        self::assertSame('provider-a', $fact->definition()['provider_key']);
        self::assertSame('engagement', $fact->definition()['provider_metric']);
        self::assertFalse($fact->definition()['cross_provider_equivalent']);
    }

    public function test_negative_values_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EngagementFact('tenant-a', 'provider-a', 'engagement', -1, 'https://provider.test/events/1', '2026-10-07T10:00:00+00:00', true);
    }

    public function test_out_of_order_or_non_utc_receipts_fail_closed(): void
    {
        foreach ([
            ['2026-10-07T10:00:00+00:00', '2026-10-07T09:59:59+00:00'],
            ['2026-10-07T10:00:00+05:00', '2026-10-07T10:01:00+05:00'],
        ] as [$observed, $received]) {
            try {
                new EngagementFact('tenant-a', 'provider-a', 'engagement', 1, 'source', $observed, false, $received);
                self::fail('Invalid provider analytics timestamps were accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
