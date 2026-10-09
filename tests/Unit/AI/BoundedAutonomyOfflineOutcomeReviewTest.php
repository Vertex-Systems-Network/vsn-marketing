<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineOutcomeReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyOutcomeSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyOutcomeSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineOutcomeReviewTest extends TestCase
{
    private function scope(): TenantContext
    {
        return new TenantContext('org', 'workspace', null, 'actor');
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    }

    private function receipt(): array
    {
        return [
            'status' => 'recorded_offline',
            'run_id' => 'run-1',
            'tenant' => $this->scope()->toArray(),
            'snapshot_sha256' => str_repeat('a', 64),
            'execution_authorized' => false,
            'stages' => ['execute' => 'disabled'],
        ];
    }

    private function evidence(): array
    {
        return [
            'workspace_id' => 'workspace',
            'brand_id' => null,
            'attempt_id' => 'attempt-1',
            'snapshot_sha256' => str_repeat('a', 64),
            'evidence_sha256' => str_repeat('b', 64),
            'provider_event_id' => 'provider-event-1',
            'observed_at_unix' => $this->at()->getTimestamp(),
            'state' => 'confirmed_no_external_effect',
        ];
    }

    private function observer(object $current): BoundedAutonomyOfflineOutcomeReview
    {
        $source = $this->createMock(BoundedAutonomyOutcomeSource::class);
        $source->method('verifiedOutcome')->willReturnCallback(static function () use ($current): ?array {
            return $current->value;
        });

        return new BoundedAutonomyOfflineOutcomeReview($source);
    }

    public function test_default_unknown_never_retries_or_claims_external_authority(): void
    {
        $r = (new BoundedAutonomyOfflineOutcomeReview(new DenyingBoundedAutonomyOutcomeSource))
            ->inspect($this->scope(), $this->receipt(), 'attempt-1', $this->at());
        self::assertSame('independent_outcome_unavailable', $r['reason_code']);
        self::assertFalse($r['execution_authorized']);
        self::assertFalse($r['retry_authorized']);
        self::assertFalse($r['automatic_rollback_authorized']);
        self::assertFalse($r['promotion_authorized']);
    }

    public function test_even_verified_no_effect_requires_fresh_separate_admission(): void
    {
        $facts = (object) ['value' => $this->evidence()];
        $r = $this->observer($facts)->inspect($this->scope(), $this->receipt(), 'attempt-1', $this->at());
        self::assertSame('fresh_admission_required', $r['reason_code']);
        self::assertTrue($r['verified_no_effect']);
        self::assertFalse($r['retry_authorized']);
    }

    public function test_irreversible_and_unresolved_outcomes_prohibit_automatic_rollback_or_resend(): void
    {
        $facts = (object) ['value' => array_replace($this->evidence(), ['state' => 'confirmed_irreversible_external_effect'])];
        $review = $this->observer($facts);
        $r = $review->inspect($this->scope(), $this->receipt(), 'attempt-1', $this->at());
        self::assertSame('irreversible_manual_reconciliation_required', $r['reason_code']);
        self::assertFalse($r['verified_no_effect']);
        self::assertFalse($r['automatic_rollback_authorized']);

        $facts->value['state'] = 'unresolved';
        $unresolved = $review->inspect($this->scope(), $this->receipt(), 'attempt-1', $this->at());
        self::assertSame('outcome_unresolved_manual_reconciliation', $unresolved['reason_code']);
        self::assertFalse($unresolved['retry_authorized']);
    }

    public function test_forged_receipts_foreign_evidence_future_metrics_and_escalated_fields_fail_closed(): void
    {
        $facts = (object) ['value' => $this->evidence()];
        $review = $this->observer($facts);
        foreach ([
            [$this->scope(), array_replace($this->receipt(), ['execution_authorized' => true])],
            [new TenantContext('other-org', 'workspace', null, 'actor'), $this->receipt()],
            [$this->scope(), array_replace($this->receipt(), ['snapshot_sha256' => 'invalid'])],
        ] as [$scope, $receipt]) {
            try {
                $review->inspect($scope, $receipt, 'attempt-1', $this->at());
                self::fail('Untrusted autonomy outcome receipt accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([
            ['workspace_id' => 'foreign'],
            ['attempt_id' => 'another-attempt'],
            ['snapshot_sha256' => str_repeat('f', 64)],
            ['evidence_sha256' => 'invalid'],
            ['observed_at_unix' => $this->at()->getTimestamp() + 1],
            ['state' => 'provider_may_have_sent'],
            ['send_authorized' => true],
        ] as $patch) {
            $facts->value = array_replace($this->evidence(), $patch);
            try {
                $review->inspect($this->scope(), $this->receipt(), 'attempt-1', $this->at());
                self::fail('Untrusted autonomy outcome evidence accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
