<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineJointRollbackReview;
use App\Modules\AI\Application\BoundedAutonomyOfflineOperationCoverageReview;
use App\Modules\AI\Application\BoundedAutonomyOfflineProviderAttemptReview;
use App\Modules\AI\Application\BoundedAutonomyOfflineRollbackReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyExpectedProviderOperationsSource;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyRollbackOutcomeSource;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyVerifiedProviderAttemptSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyExpectedProviderOperationsSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineOperationCoverageReviewTest extends TestCase
{
    private function actor(string $id = 'operator'): TenantContext
    {
        return new TenantContext('org', 'workspace', 'brand', $id);
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-10T00:00:00+00:00');
    }

    private function identities(): array
    {
        return [
            ['operation_id' => 'operation-1', 'idempotency_key' => 'key-1'],
            ['operation_id' => 'operation-2', 'idempotency_key' => 'key-2'],
        ];
    }

    private function inventory(array $identities, bool $complete = true): array
    {
        $sorted = $identities;
        usort($sorted, static fn (array $a, array $b): int => [$a['operation_id'], $a['idempotency_key']] <=> [$b['operation_id'], $b['idempotency_key']]);

        return [
            'tenant' => $this->actor()->toArray(),
            'run_id' => 'run-1',
            'snapshot_sha256' => str_repeat('a', 64),
            'observed_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 60,
            'complete' => $complete,
            'operations' => $identities,
            'operation_set_sha256' => hash('sha256', json_encode($sorted, JSON_THROW_ON_ERROR)),
        ];
    }

    private function attempts(): array
    {
        $attempts = array_map(fn (array $identity): array => [
            'operation_id' => $identity['operation_id'],
            'idempotency_key' => $identity['idempotency_key'],
            'receipt_sha256' => str_repeat('b', 64),
            'attempted_at_unix' => $this->at()->getTimestamp() - 120,
            'outcome' => 'confirmed_not_applied',
            'verified_independently' => true,
            'late' => false,
            'callback_count' => 1,
            'external_cost_minor' => 0,
            'irreversible' => false,
        ], $this->identities());
        $body = [
            'tenant' => $this->actor()->toArray(),
            'run_id' => 'run-1',
            'snapshot_sha256' => str_repeat('a', 64),
            'captured_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 60,
            'complete' => true,
            'attempts' => $attempts,
        ];

        return [
            'tenant' => $body['tenant'], 'run_id' => $body['run_id'],
            'snapshot_sha256' => $body['snapshot_sha256'],
            'source_manifest_sha256' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
            'captured_at_unix' => $body['captured_at_unix'],
            'expires_at_unix' => $body['expires_at_unix'],
            'complete' => $body['complete'], 'attempts' => $body['attempts'],
        ];
    }

    private function aggregate(): array
    {
        return [
            'tenant' => $this->actor()->toArray(),
            'run_id' => 'run-1',
            'snapshot_sha256' => str_repeat('a', 64),
            'source_sha256' => str_repeat('f', 64),
            'observed_at_unix' => $this->at()->getTimestamp(),
            'attempted_at_unix' => $this->at()->getTimestamp() - 120,
            'callback_count' => 1,
            'outcome' => 'confirmed_not_applied',
            'outcome_verified_independently' => true,
            'late_callback' => false, 'duplicate_callback' => false,
            'external_cost_minor' => 0,
        ];
    }

    private function reviewer(?array $inventory, ?array $provider = null): BoundedAutonomyOfflineOperationCoverageReview
    {
        $aggregateSource = $this->createMock(BoundedAutonomyRollbackOutcomeSource::class);
        $aggregateSource->method('latest')->willReturn($this->aggregate());
        $providerSource = $this->createMock(BoundedAutonomyVerifiedProviderAttemptSource::class);
        $providerSource->method('latest')->willReturn($provider ?? $this->attempts());
        $inventorySource = $this->createMock(BoundedAutonomyExpectedProviderOperationsSource::class);
        $inventorySource->method('latest')->willReturn($inventory);

        return new BoundedAutonomyOfflineOperationCoverageReview(
            new BoundedAutonomyOfflineJointRollbackReview(
                new BoundedAutonomyOfflineRollbackReview($aggregateSource),
                new BoundedAutonomyOfflineProviderAttemptReview($providerSource),
            ),
            $inventorySource,
        );
    }

    private function inspect(BoundedAutonomyOfflineOperationCoverageReview $reviewer): array
    {
        return $reviewer->inspect($this->actor(), 'run-1', str_repeat('a', 64), $this->at());
    }

    public function test_exact_independent_operation_coverage_is_only_an_offline_candidate(): void
    {
        $review = $this->inspect($this->reviewer($this->inventory($this->identities())));
        self::assertSame('offline_complete_non_effect_review_candidate', $review['status']);
        self::assertSame(2, $review['verified_attempt_count']);
        self::assertFalse($review['rollback_performed']);
        self::assertFalse($review['refund_authorized']);
        self::assertFalse($review['retry_authorized']);
        self::assertFalse($review['execution_authorized']);
        self::assertFalse($review['promotion_authorized']);
    }

    public function test_missing_and_incomplete_inventory_never_certify_provider_completeness(): void
    {
        $held = $this->inspect($this->reviewer(null));
        self::assertSame('canonical_operation_inventory_unavailable', $held['reason_code']);
        $partial = $this->inspect($this->reviewer($this->inventory([$this->identities()[0]])));
        self::assertSame('provider_attempt_inventory_mismatch', $partial['reason_code']);
        $untrusted = $this->inspect($this->reviewer($this->inventory($this->identities(), false)));
        self::assertSame('canonical_operation_inventory_incomplete', $untrusted['reason_code']);
        self::assertFalse($partial['rollback_performed']);
    }

    public function test_same_count_but_different_idempotency_key_is_not_a_complete_provider_set(): void
    {
        $changed = $this->identities();
        $changed[1]['idempotency_key'] = 'different-key';
        $r = $this->inspect($this->reviewer($this->inventory($changed)));
        self::assertSame('provider_attempt_inventory_mismatch', $r['reason_code']);
        self::assertFalse($r['execution_authorized']);
    }

    public function test_duplicate_or_tampered_canonical_operation_evidence_is_held_or_rejected(): void
    {
        $duplicated = $this->identities();
        $duplicated[1]['idempotency_key'] = 'key-1';
        $r = $this->inspect($this->reviewer($this->inventory($duplicated)));
        self::assertSame('duplicate_canonical_operation_identity', $r['reason_code']);

        foreach ([
            array_replace($this->inventory($this->identities()), ['operation_set_sha256' => str_repeat('0', 64)]),
            array_replace($this->inventory($this->identities()), ['tenant' => $this->actor('imposter')->toArray()]),
            array_replace($this->inventory($this->identities()), ['expires_at_unix' => $this->at()->getTimestamp()]),
            array_replace($this->inventory($this->identities()), ['send_authorized' => true]),
        ] as $bad) {
            try {
                $this->inspect($this->reviewer($bad));
                self::fail('Untrusted canonical operation inventory was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_unresolved_provider_attempt_blocks_even_a_complete_expected_inventory(): void
    {
        $attempts = $this->attempts();
        $attempts['attempts'][0]['outcome'] = 'confirmed_applied';
        // Manifest tampering, even before the confirmed-applied verdict,
        // must never become a positive candidate.
        $r = $this->inspect($this->reviewer($this->inventory($this->identities()), $attempts));
        self::assertSame('joint_provider_outcome_unresolved', $r['reason_code']);
        self::assertFalse($r['rollback_performed']);
    }
}
