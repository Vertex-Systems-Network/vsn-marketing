<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Domain\BoundedAutonomyArmEvidenceDigest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyArmEvidenceDigestTest extends TestCase
{
    public function test_digest_is_order_independent_but_binds_exact_arm_allocation_and_plan(): void
    {
        $plan = str_repeat('a', 64);
        $expected = BoundedAutonomyArmEvidenceDigest::sha256($plan, [
            'control' => 450, 'holdout' => 100, 'treatment' => 450,
        ]);
        self::assertSame($expected, BoundedAutonomyArmEvidenceDigest::sha256($plan, [
            'treatment' => 450, 'control' => 450, 'holdout' => 100,
        ]));
        self::assertNotSame($expected, BoundedAutonomyArmEvidenceDigest::sha256($plan, [
            'control' => 449, 'treatment' => 451, 'holdout' => 100,
        ]));
        self::assertNotSame($expected, BoundedAutonomyArmEvidenceDigest::sha256(str_repeat('b', 64), [
            'control' => 450, 'holdout' => 100, 'treatment' => 450,
        ]));
    }

    public function test_digest_rejects_untyped_and_unbounded_assignment_evidence(): void
    {
        foreach ([
            ['', ['control' => 450]],
            [str_repeat('x', 64), ['control' => 450]],
            [str_repeat('a', 64), []],
            [str_repeat('a', 64), ['control' => -1]],
            [str_repeat('a', 64), ['control' => 1000001]],
            [str_repeat('a', 64), ['control' => '450']],
            [str_repeat('a', 64), [0 => 450]],
        ] as [$plan, $counts]) {
            try {
                BoundedAutonomyArmEvidenceDigest::sha256($plan, $counts);
                self::fail('Untrusted frozen arm allocation was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
