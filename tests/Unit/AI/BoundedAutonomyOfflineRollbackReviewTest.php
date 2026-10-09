<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineRollbackReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyRollbackOutcomeSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyRollbackOutcomeSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineRollbackReviewTest extends TestCase
{
    private function scope(): TenantContext
    {
        return new TenantContext('org', 'workspace', null, 'operator');
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    }

    private function record(): array
    {
        return [
            'tenant' => $this->scope()->toArray(),
            'run_id' => 'run-1',
            'snapshot_sha256' => str_repeat('a', 64),
            'source_sha256' => str_repeat('b', 64),
            'observed_at_unix' => $this->at()->getTimestamp(),
            'attempted_at_unix' => $this->at()->getTimestamp() - 600,
            'callback_count' => 1,
            'outcome' => 'confirmed_not_applied',
            'outcome_verified_independently' => true,
            'late_callback' => false,
            'duplicate_callback' => false,
            'external_cost_minor' => 0,
        ];
    }

    private function reviewer(object $data): BoundedAutonomyOfflineRollbackReview
    {
        $source = $this->createMock(BoundedAutonomyRollbackOutcomeSource::class);
        $source->method('latest')->willReturnCallback(static function () use ($data): ?array {
            return $data->current;
        });

        return new BoundedAutonomyOfflineRollbackReview($source);
    }

    public function test_missing_provider_evidence_never_yields_rollback_or_refund(): void
    {
        $review = new BoundedAutonomyOfflineRollbackReview(new DenyingBoundedAutonomyRollbackOutcomeSource);
        $r = $review->inspect($this->scope(), 'run-1', str_repeat('a', 64), $this->at());
        self::assertSame('provider_outcome_unknown', $r['reason_code']);
        self::assertFalse($r['refund_authorized']);
        self::assertFalse($r['rollback_performed']);
        self::assertFalse($r['execution_authorized']);
    }

    public function test_verified_non_effect_still_requires_human_rollback_approval(): void
    {
        $state = (object) ['current' => $this->record()];
        $r = $this->reviewer($state)->inspect($this->scope(), 'run-1', str_repeat('a', 64), $this->at());
        self::assertSame('offline_rollback_review_ready', $r['status']);
        self::assertTrue($r['external_outcome_verified']);
        self::assertFalse($r['rollback_performed']);
        self::assertFalse($r['refund_authorized']);
        self::assertFalse($r['retry_authorized']);
        self::assertFalse($r['promotion_authorized']);
    }

    public function test_unknown_applied_late_duplicate_and_nonzero_cost_hold(): void
    {
        $state = (object) ['current' => $this->record()];
        $reviewer = $this->reviewer($state);
        foreach ([
            ['outcome' => 'unknown'],
            ['outcome_verified_independently' => false],
            ['outcome' => 'confirmed_applied'],
            ['late_callback' => true],
            ['duplicate_callback' => true],
            ['callback_count' => 2],
            ['external_cost_minor' => 10],
        ] as $change) {
            $state->current = array_replace($this->record(), $change);
            $r = $reviewer->inspect($this->scope(), 'run-1', str_repeat('a', 64), $this->at());
            self::assertSame('held_offline', $r['status']);
            self::assertFalse($r['refund_authorized']);
        }
    }

    public function test_cross_tenant_future_callback_spoofed_evidence_fail_closed(): void
    {
        $state = (object) ['current' => $this->record()];
        $reviewer = $this->reviewer($state);
        foreach ([
            ['tenant' => (new TenantContext('foreign', 'workspace', null, 'operator'))->toArray()],
            ['run_id' => 'other'],
            ['snapshot_sha256' => str_repeat('c', 64)],
            ['source_sha256' => 'invalid'],
            ['observed_at_unix' => $this->at()->getTimestamp() + 1],
            ['attempted_at_unix' => $this->at()->getTimestamp() - 3601],
            ['attempted_at_unix' => -1],
            ['callback_count' => 'one'],
            ['send_authorized' => true],
        ] as $change) {
            $state->current = array_replace($this->record(), $change);
            try {
                $reviewer->inspect($this->scope(), 'run-1', str_repeat('a', 64), $this->at());
                self::fail('Forged rollback evidence accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
