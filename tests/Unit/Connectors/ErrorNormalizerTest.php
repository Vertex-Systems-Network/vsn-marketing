<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use App\Connectors\ErrorNormalizer\BaseErrorNormalizer;
use App\Connectors\Enums\ErrorCategory;

class ErrorNormalizerTest extends TestCase
{
    public function test_normalizes_array_error()
    {
        $n = new BaseErrorNormalizer();
        $out = $n->normalize(['error' => ['type' => 'validation', 'message' => 'bad']]);
        $this->assertEquals(ErrorCategory::VALIDATION, $out['category']);
        $this->assertArrayHasKey('details', $out);
    }

    public function test_falls_back_for_string()
    {
        $n = new BaseErrorNormalizer();
        $out = $n->normalize('unexpected string');
        $this->assertEquals(ErrorCategory::PERMANENT, $out['category']);
        $this->assertArrayHasKey('details', $out);
    }
}
