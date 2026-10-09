<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineCanaryReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryCohortSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryCohortSource;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineCanaryReviewTest extends TestCase
{
    private function scope(string $actor = 'operator'): TenantContext
    {
        return new TenantContext('org', 'workspace', null, $actor);
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

    private function facts(): array
    {
        $at = $this->at()->getTimestamp();

        return [
            'tenant' => $this->scope()->toArray(),
            'plan_sha256' => $this->plan()->fingerprint(),
            'assignment_manifest_sha256' => str_repeat('a', 64),
            'observed_at_unix' => $at,
            'expires_at_unix' => $at + 300,
            'frozen' => true,
            'consent_verified' => true,
            'quarantined' => 0,
            'crossovers' => 0,
            'counts' => [
                'control' => ['eligible' => 450, 'assigned' => 450, 'exposed' => 420],
                'treatment' => ['eligible' => 450, 'assigned' => 450, 'exposed' => 410],
                'holdout' => ['eligible' => 100, 'assigned' => 100, 'exposed' => 0],
            ],
        ];
    }

    private function reviewer(object $sourceData): BoundedAutonomyOfflineCanaryReview
    {
        $source = $this->createMock(BoundedAutonomyCanaryCohortSource::class);
        $source->method('snapshot')->willReturnCallback(static function () use ($sourceData): ?array {
            return $sourceData->facts;
        });

        return new BoundedAutonomyOfflineCanaryReview($source);
    }

    public function test_unbound_source_holds_everything_without_assignment_or_send_authority(): void
    {
        $review = new BoundedAutonomyOfflineCanaryReview(new DenyingBoundedAutonomyCanaryCohortSource);
        $res = $review->inspect($this->scope(), $this->plan(), $this->at());
        self::assertSame('held_offline', $res['status']);
        self::assertSame('independent_cohort_unavailable', $res['reason_code']);
        self::assertFalse($res['promotion_authorized']);
        self::assertFalse($res['exposure_authorized']);
    }

    public function test_valid_frozen_consent_and_holdout_only_yield_offline_review(): void
    {
        $x = (object) ['facts' => $this->facts()];
        $res = $this->reviewer($x)->inspect($this->scope(), $this->plan(), $this->at());
        self::assertSame('offline_cohort_review_ready', $res['status']);
        self::assertSame(1000, $res['assigned_denominator']);
        self::assertSame(100, $res['holdout_denominator']);
        self::assertFalse($res['exposure_authorized']);
        self::assertFalse($res['execution_authorized']);
        self::assertFalse($res['promotion_authorized']);
    }

    public function test_unfrozen_unconsented_quarantined_and_exposed_holdout_are_held(): void
    {
        $x = (object) ['facts' => $this->facts()];
        $review = $this->reviewer($x);
        foreach ([
            ['frozen' => false], ['consent_verified' => false],
            ['quarantined' => 1], ['crossovers' => 1],
            ['counts' => array_replace($this->facts()['counts'],
                ['holdout' => ['eligible' => 100, 'assigned' => 100, 'exposed' => 1]])],
            ['counts' => array_replace($this->facts()['counts'],
                ['treatment' => ['eligible' => 450, 'assigned' => 35, 'exposed' => 25]])],
            ['counts' => array_replace($this->facts()['counts'],
                ['treatment' => ['eligible' => 450, 'assigned' => 100, 'exposed' => 80]])],
        ] as $patch) {
            $x->facts = array_replace($this->facts(), $patch);
            $res = $review->inspect($this->scope(), $this->plan(), $this->at());
            self::assertSame('held_offline', $res['status']);
            self::assertFalse($res['promotion_authorized']);
        }
    }

    public function test_forged_tenant_plan_clock_and_unknown_fields_are_rejected(): void
    {
        $x = (object) ['facts' => $this->facts()];
        $review = $this->reviewer($x);
        foreach ([
            ['tenant' => $this->scope('other')->toArray()],
            ['plan_sha256' => str_repeat('b', 64)],
            ['assignment_manifest_sha256' => 'tampered'],
            ['observed_at_unix' => $this->at()->getTimestamp() - 301],
            ['expires_at_unix' => $this->at()->getTimestamp()],
            ['publication_authorized' => true],
            ['counts' => array_replace($this->facts()['counts'], ['spam' => ['eligible' => 10, 'assigned' => 5, 'exposed' => 1]])],
        ] as $patch) {
            $x->facts = array_replace($this->facts(), $patch);
            try {
                $review->inspect($this->scope(), $this->plan(), $this->at());
                self::fail('Unsafe canary evidence accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $x->facts = $this->facts();
        $other = new TenantContext('org', 'another', null, 'operator');
        $this->expectException(InvalidArgumentException::class);
        $review->inspect($other, $this->plan(), $this->at());
    }
}
