<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineJointRollbackReview;
use App\Modules\AI\Application\BoundedAutonomyOfflineProviderAttemptReview;
use App\Modules\AI\Application\BoundedAutonomyOfflineRollbackReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyRollbackOutcomeSource;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyVerifiedProviderAttemptSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineJointRollbackReviewTest extends TestCase
{
    private function actor(): TenantContext
    {
        return new TenantContext('org', 'workspace', 'brand', 'operator');
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T16:00:00+00:00');
    }

    private function aggregateFact(string $outcome = 'confirmed_not_applied'): array
    {
        return [
            'tenant' => $this->actor()->toArray(),
            'run_id' => 'run-1',
            'snapshot_sha256' => str_repeat('a', 64),
            'source_sha256' => str_repeat('f', 64),
            'observed_at_unix' => $this->at()->getTimestamp(),
            'attempted_at_unix' => $this->at()->getTimestamp() - 200,
            'callback_count' => 1,
            'outcome' => $outcome,
            'outcome_verified_independently' => true,
            'late_callback' => false,
            'duplicate_callback' => false,
            'external_cost_minor' => 0,
        ];
    }

    private function perAttemptFact(string $outcome = 'confirmed_not_applied'): array
    {
        $attempts = [[
            'operation_id' => 'operation-1',
            'idempotency_key' => 'key-1',
            'receipt_sha256' => str_repeat('b', 64),
            'attempted_at_unix' => $this->at()->getTimestamp() - 200,
            'outcome' => $outcome,
            'verified_independently' => true,
            'late' => false,
            'callback_count' => 1,
            'external_cost_minor' => 0,
            'irreversible' => false,
        ]];
        $data = [
            'tenant' => $this->actor()->toArray(),
            'run_id' => 'run-1',
            'snapshot_sha256' => str_repeat('a', 64),
            'source_manifest_sha256' => '',
            'captured_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 60,
            'complete' => true,
            'attempts' => $attempts,
        ];
        $data['source_manifest_sha256'] = hash('sha256', json_encode([
            'tenant' => $data['tenant'], 'run_id' => $data['run_id'],
            'snapshot_sha256' => $data['snapshot_sha256'],
            'captured_at_unix' => $data['captured_at_unix'],
            'expires_at_unix' => $data['expires_at_unix'],
            'complete' => $data['complete'],
            'attempts' => $data['attempts'],
        ], JSON_THROW_ON_ERROR));

        return $data;
    }

    private function reviewer(?array $aggregate, ?array $attempts): BoundedAutonomyOfflineJointRollbackReview
    {
        $first = $this->createMock(BoundedAutonomyRollbackOutcomeSource::class);
        $first->method('latest')->willReturn($aggregate);
        $second = $this->createMock(BoundedAutonomyVerifiedProviderAttemptSource::class);
        $second->method('latest')->willReturn($attempts);

        return new BoundedAutonomyOfflineJointRollbackReview(
            new BoundedAutonomyOfflineRollbackReview($first),
            new BoundedAutonomyOfflineProviderAttemptReview($second),
        );
    }

    private function inspect(BoundedAutonomyOfflineJointRollbackReview $reviewer): array
    {
        return $reviewer->inspect($this->actor(), 'run-1', str_repeat('a', 64), $this->at());
    }

    public function test_no_source_or_only_aggregate_evidence_never_confirms_rollback(): void
    {
        $missing = $this->inspect($this->reviewer(null, null));
        self::assertSame('held_offline', $missing['status']);
        self::assertSame('aggregate_provider_outcome_unresolved', $missing['reason_code']);

        $partial = $this->inspect($this->reviewer($this->aggregateFact(), null));
        self::assertSame('individual_provider_attempts_unresolved', $partial['reason_code']);
        self::assertFalse($partial['rollback_performed']);
        self::assertFalse($partial['refund_authorized']);
    }

    public function test_both_independently_verified_non_effects_can_only_request_human_review(): void
    {
        $r = $this->inspect($this->reviewer($this->aggregateFact(), $this->perAttemptFact()));

        self::assertSame('offline_joint_rollback_review_ready', $r['status']);
        self::assertSame('independent_human_recovery_gate_required', $r['reason_code']);
        self::assertSame(1, $r['verified_attempt_count']);
        self::assertFalse($r['rollback_performed']);
        self::assertFalse($r['refund_authorized']);
        self::assertFalse($r['retry_authorized']);
        self::assertFalse($r['execution_authorized']);
        self::assertFalse($r['promotion_authorized']);
    }

    public function test_applied_aggregate_or_provider_attempt_never_returns_positive_joint_review(): void
    {
        $external = $this->inspect($this->reviewer($this->aggregateFact('confirmed_applied'), $this->perAttemptFact()));
        self::assertSame('aggregate_provider_outcome_unresolved', $external['reason_code']);

        $attempt = $this->inspect($this->reviewer($this->aggregateFact(), $this->perAttemptFact('confirmed_applied')));
        self::assertSame('individual_provider_attempts_unresolved', $attempt['reason_code']);
        self::assertFalse($attempt['rollback_performed']);
    }
}
