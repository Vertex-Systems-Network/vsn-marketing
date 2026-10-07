<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Domain\Listening\ListeningSignal;
use App\Modules\Providers\Domain\Listening\ListeningSourceType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Task0079ListeningBoundaryTest extends TestCase
{
    public function test_only_official_sources_are_representable(): void
    {
        self::assertSame('official_api', ListeningSourceType::OfficialApi->value);
        self::assertSame('official_webhook', ListeningSourceType::OfficialWebhook->value);
    }

    public function test_retention_scope_rate_limit_and_coverage_are_explicit(): void
    {
        $signal = new ListeningSignal('tenant-a', 'provider-a', 'signal-1', ListeningSourceType::OfficialApi, 'read:mentions', 30, 60, 'https://provider.test/signal');
        self::assertSame(30, $signal->retentionDays);
        self::assertSame('read:mentions', $signal->scope);
        self::assertFalse($signal->isCoverageKnown());
    }

    public function test_invalid_boundaries_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ListeningSignal('tenant-a', 'provider-a', 'signal-1', ListeningSourceType::OfficialApi, 'read:mentions', 0, 60, 'http://provider.test/signal');
    }
}
