<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflinePromotionReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryCohortSource;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryOutcomeSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryCohortSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryOutcomeSource;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflinePromotionReviewTest extends TestCase
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

    private function analysis(): ExperimentAnalysisPlan
    {
        return new ExperimentAnalysisPlan(
            '22222222-2222-4222-8222-222222222222',
            'company', ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000],
            'control', 'holdout', 0.05, 0.8, 0.2, 0.15,
            new DateTimeImmutable('2026-10-08T09:00:00+00:00'),
        );
    }

    private function cohort(): array
    {
        return [
            'tenant' => $this->scope()->toArray(),
            'plan_sha256' => $this->plan()->fingerprint(),
            'assignment_manifest_sha256' => str_repeat('a', 64),
            'observed_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 300,
            'frozen' => true, 'consent_verified' => true,
            'quarantined' => 0, 'crossovers' => 0,
            'counts' => [
                'control' => ['eligible' => 4500, 'assigned' => 4500, 'exposed' => 4500],
                'treatment' => ['eligible' => 4500, 'assigned' => 4500, 'exposed' => 4500],
                'holdout' => ['eligible' => 1000, 'assigned' => 1000, 'exposed' => 0],
            ],
        ];
    }

    private function outcome(): array
    {
        return [
            'tenant' => $this->scope()->toArray(),
            'experiment_id' => $this->plan()->id,
            'plan_sha256' => $this->plan()->fingerprint(),
            'analysis_sha256' => $this->analysis()->fingerprint(),
            'source_manifest_sha256' => str_repeat('b', 64),
            'assignment_manifest_sha256' => str_repeat('a', 64),
            'observed_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 300,
            'verified_independent_source' => true,
            'assigned' => ['control' => 4500, 'treatment' => 4500, 'holdout' => 1000],
            'exposed' => ['control' => 4500, 'treatment' => 4500, 'holdout' => 0],
            'outcomes' => ['control' => 450, 'treatment' => 1800, 'holdout' => 0],
            'quarantined' => 0, 'crossovers' => 0, 'low_trust_count' => 0,
        ];
    }

    private function review(object $cohort, object $outcome): BoundedAutonomyOfflinePromotionReview
    {
        $sourceC = $this->createMock(BoundedAutonomyCanaryCohortSource::class);
        $sourceC->method('snapshot')->willReturnCallback(static function () use ($cohort): ?array {
            return $cohort->value;
        });
        $sourceO = $this->createMock(BoundedAutonomyCanaryOutcomeSource::class);
        $sourceO->method('snapshot')->willReturnCallback(static function () use ($outcome): ?array {
            return $outcome->value;
        });

        return new BoundedAutonomyOfflinePromotionReview($sourceC, $sourceO);
    }

    public function test_denied_sources_never_propose_external_promotion(): void
    {
        $review = new BoundedAutonomyOfflinePromotionReview(
            new DenyingBoundedAutonomyCanaryCohortSource,
            new DenyingBoundedAutonomyCanaryOutcomeSource,
        );
        $result = $review->inspect($this->scope(), $this->plan(), $this->analysis(), $this->at());
        self::assertSame('held_offline', $result['status']);
        self::assertFalse($result['promotion_authorized']);
    }

    public function test_two_source_exact_match_is_operator_review_only(): void
    {
        $cohort = (object) ['value' => $this->cohort()];
        $outcome = (object) ['value' => $this->outcome()];
        $result = $this->review($cohort, $outcome)->inspect(
            $this->scope(), $this->plan(), $this->analysis(), $this->at(),
        );
        self::assertSame('offline_promotion_review_candidate', $result['status']);
        self::assertSame(str_repeat('a', 64), $result['assignment_manifest_sha256']);
        self::assertFalse($result['promotion_authorized']);
        self::assertFalse($result['execution_authorized']);
        self::assertFalse($result['publication_authorized']);
    }

    public function test_mismatched_assignment_or_denominators_and_spoofed_events_hold(): void
    {
        $cohort = (object) ['value' => $this->cohort()];
        $outcome = (object) ['value' => $this->outcome()];
        $review = $this->review($cohort, $outcome);

        $outcome->value['assignment_manifest_sha256'] = str_repeat('c', 64);
        self::assertSame('independent_cohort_outcome_binding_mismatch',
            $review->inspect($this->scope(), $this->plan(), $this->analysis(), $this->at())['reason_code']);

        $outcome->value = array_replace($this->outcome(), [
            'assigned' => ['control' => 4400, 'treatment' => 4600, 'holdout' => 1000],
            'exposed' => ['control' => 4400, 'treatment' => 4600, 'holdout' => 0],
        ]);
        self::assertSame('independent_cohort_outcome_binding_mismatch',
            $review->inspect($this->scope(), $this->plan(), $this->analysis(), $this->at())['reason_code']);

        $outcome->value = array_replace($this->outcome(), ['low_trust_count' => 1]);
        self::assertSame('spoofed_or_quarantined_event',
            $review->inspect($this->scope(), $this->plan(), $this->analysis(), $this->at())['reason_code']);

        $outcome->value = $this->outcome();
        $cohort->value['consent_verified'] = false;
        self::assertSame('cohort_not_frozen_or_consent_unverified',
            $review->inspect($this->scope(), $this->plan(), $this->analysis(), $this->at())['reason_code']);
    }
}
