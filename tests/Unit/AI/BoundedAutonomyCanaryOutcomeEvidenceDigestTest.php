<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Domain\BoundedAutonomyCanaryOutcomeEvidenceDigest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyCanaryOutcomeEvidenceDigestTest extends TestCase
{
    private function facts(): array
    {
        return [
            'tenant' => ['organization_id' => 'org', 'workspace_id' => 'workspace', 'brand_id' => null, 'actor_id' => 'operator'],
            'experiment_id' => 'canary-1',
            'plan_sha256' => str_repeat('a', 64),
            'analysis_sha256' => str_repeat('b', 64),
            'observed_at_unix' => 1760000000,
            'expires_at_unix' => 1760000300,
            'verified_independent_source' => true,
            'assigned' => ['control' => 450, 'treatment' => 450, 'holdout' => 100],
            'exposed' => ['control' => 420, 'treatment' => 440, 'holdout' => 0],
            'outcomes' => ['control' => 45, 'treatment' => 90, 'holdout' => 0],
            'quarantined' => 0,
            'crossovers' => 0,
            'low_trust_count' => 0,
        ];
    }

    public function test_manifest_is_order_independent_but_sensitive_to_conversion_denominator_and_snapshot_identity(): void
    {
        $base = $this->facts();
        $hash = BoundedAutonomyCanaryOutcomeEvidenceDigest::sha256($base);
        $reordered = $base;
        $reordered['assigned'] = ['holdout' => 100, 'treatment' => 450, 'control' => 450];
        $reordered['exposed'] = ['holdout' => 0, 'control' => 420, 'treatment' => 440];
        $reordered['outcomes'] = ['treatment' => 90, 'holdout' => 0, 'control' => 45];
        self::assertSame($hash, BoundedAutonomyCanaryOutcomeEvidenceDigest::sha256($reordered));

        foreach ([
            ['outcomes' => ['control' => 46, 'treatment' => 90, 'holdout' => 0]],
            ['assigned' => ['control' => 449, 'treatment' => 451, 'holdout' => 100]],
            ['exposed' => ['control' => 419, 'treatment' => 440, 'holdout' => 0]],
            ['observed_at_unix' => 1759999999],
            ['plan_sha256' => str_repeat('c', 64)],
            ['verified_independent_source' => false],
            ['low_trust_count' => 1],
        ] as $patch) {
            self::assertNotSame($hash, BoundedAutonomyCanaryOutcomeEvidenceDigest::sha256(
                array_replace($base, $patch),
            ));
        }
    }

    public function test_incomplete_manifest_is_rejected(): void
    {
        $missing = $this->facts();
        unset($missing['outcomes']);
        $this->expectException(InvalidArgumentException::class);
        BoundedAutonomyCanaryOutcomeEvidenceDigest::sha256($missing);
    }

    public function test_untyped_arm_manifest_is_rejected(): void
    {
        $bad = $this->facts();
        $bad['assigned'] = 'untrusted-counts';
        $this->expectException(InvalidArgumentException::class);
        BoundedAutonomyCanaryOutcomeEvidenceDigest::sha256($bad);
    }
}
