<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineCanaryScore;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineCanaryScoreTest extends TestCase
{
    private function scope(): TenantContext
    {
        return new TenantContext('org', 'workspace', null, 'operator');
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    }

    private function plan(): ExperimentPlan
    {
        return new ExperimentPlan(
            '11111111-1111-4111-8111-111111111111',
            'workspace', null, 'offline_canary', 'company',
            ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000],
            'control', 'holdout',
        );
    }

    private function analysisPlan(): ExperimentAnalysisPlan
    {
        return new ExperimentAnalysisPlan(
            '22222222-2222-4222-8222-222222222222',
            'company',
            ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000],
            'control', 'holdout', 0.05, 0.8, 0.2, 0.15,
            new DateTimeImmutable('2026-10-08T09:00:00+00:00'),
        );
    }

    private function facts(): array
    {
        return [
            'tenant' => $this->scope()->toArray(),
            'experiment_id' => $this->plan()->id,
            'plan_sha256' => $this->plan()->fingerprint(),
            'analysis_sha256' => $this->analysisPlan()->fingerprint(),
            'source_manifest_sha256' => str_repeat('a', 64),
            'observed_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 300,
            'verified_independent_source' => true,
            'assigned' => ['control' => 5000, 'treatment' => 5000, 'holdout' => 1000],
            'exposed' => ['control' => 5000, 'treatment' => 5000, 'holdout' => 0],
            'outcomes' => ['control' => 500, 'treatment' => 2000, 'holdout' => 0],
            'quarantined' => 0, 'crossovers' => 0, 'low_trust_count' => 0,
        ];
    }

    private function reviewer(object $data): BoundedAutonomyOfflineCanaryScore
    {
        $src = $this->createMock(BoundedAutonomyCanaryOutcomeSource::class);
        $src->method('snapshot')->willReturnCallback(static function () use ($data): ?array {
            return $data->value;
        });

        return new BoundedAutonomyOfflineCanaryScore($src);
    }

    public function test_denied_source_never_infers_conversion_or_promotion(): void
    {
        $r = (new BoundedAutonomyOfflineCanaryScore(new DenyingBoundedAutonomyCanaryOutcomeSource))
            ->inspect($this->scope(), $this->plan(), $this->analysisPlan(), $this->at());
        self::assertSame('held_offline', $r['status']);
        self::assertSame('independent_outcome_unavailable', $r['reason_code']);
        self::assertFalse($r['promotion_authorized']);
        self::assertFalse($r['external_outcome_proven']);
    }

    public function test_realistic_fixture_signal_only_offers_offline_operator_review(): void
    {
        $data = (object) ['value' => $this->facts()];
        $r = $this->reviewer($data)->inspect($this->scope(), $this->plan(), $this->analysisPlan(), $this->at());
        self::assertSame('offline_operator_review_candidate', $r['status']);
        self::assertSame('offline_signal', $r['statistical_status']);
        self::assertFalse($r['promotion_authorized']);
        self::assertFalse($r['execution_authorized']);
        self::assertFalse($r['external_outcome_proven']);
    }

    public function test_low_trust_spoofed_horizon_and_corrupted_denominators_fail_closed(): void
    {
        $data = (object) ['value' => $this->facts()];
        $reviewer = $this->reviewer($data);
        foreach ([
            ['verified_independent_source' => false],
            ['low_trust_count' => 1],
            ['quarantined' => 1],
            ['crossovers' => 1],
            ['outcomes' => ['control' => 6000, 'treatment' => 2000, 'holdout' => 0]],
            ['outcomes' => ['control' => 500, 'treatment' => 2000, 'holdout' => 1]],
            ['assigned' => ['control' => 30, 'treatment' => 30, 'holdout' => 10]],
            ['outcomes' => ['control' => 2000, 'treatment' => 500, 'holdout' => 0]],
        ] as $patch) {
            $data->value = array_replace($this->facts(), $patch);
            $r = $reviewer->inspect($this->scope(), $this->plan(), $this->analysisPlan(), $this->at());
            self::assertSame('held_offline', $r['status']);
            self::assertFalse($r['promotion_authorized']);
        }
    }

    public function test_stale_plan_foreign_tenant_future_data_and_model_authority_are_rejected(): void
    {
        $data = (object) ['value' => $this->facts()];
        $reviewer = $this->reviewer($data);
        foreach ([
            ['tenant' => (new TenantContext('foreign', 'workspace', null, 'operator'))->toArray()],
            ['plan_sha256' => str_repeat('f', 64)],
            ['analysis_sha256' => str_repeat('f', 64)],
            ['source_manifest_sha256' => 'invalid'],
            ['observed_at_unix' => $this->at()->getTimestamp() + 1],
            ['expires_at_unix' => $this->at()->getTimestamp() - 1],
            ['allow_publish' => true],
        ] as $patch) {
            $data->value = array_replace($this->facts(), $patch);
            try {
                $reviewer->inspect($this->scope(), $this->plan(), $this->analysisPlan(), $this->at());
                self::fail('Untrusted offline canary outcome evidence accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
