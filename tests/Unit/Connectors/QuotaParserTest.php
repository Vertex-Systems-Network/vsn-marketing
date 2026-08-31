<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use App\Connectors\Quota\QuotaSignalParser;

class QuotaParserTest extends TestCase
{
    public function test_ingest_from_headers()
    {
        $p = new QuotaSignalParser();
        $out = $p->ingest(['headers' => ['X-RateLimit-Remaining' => '42', 'X-RateLimit-Reset' => '1700000000']]);
        $this->assertEquals(42, $out['remaining']);
        $this->assertEquals(1700000000, $out['reset_at']);
    }
}
