<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use App\Connectors\ErrorNormalizer\StripeErrorNormalizer;
use App\Connectors\Enums\ErrorCategory;

class StripeErrorNormalizerTest extends TestCase
{
    public function test_rate_limit_error()
    {
        $n = new StripeErrorNormalizer();
        $out = $n->normalize(['error' => ['type' => 'rate_limit', 'message' => 'too many requests'], 'headers' => ['Retry-After' => 30]]);
        $this->assertEquals(ErrorCategory::RATE_LIMITED, $out['category']);
        $this->assertEquals(30, $out['retry_after']);
    }

    public function test_card_error_maps_to_permanent()
    {
        $n = new StripeErrorNormalizer();
        $out = $n->normalize(['error' => ['type' => 'card_error', 'message' => 'declined']]);
        $this->assertEquals(ErrorCategory::PERMANENT, $out['category']);
    }
}
