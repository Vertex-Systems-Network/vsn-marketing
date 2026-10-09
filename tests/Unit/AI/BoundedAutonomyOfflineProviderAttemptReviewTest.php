<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineProviderAttemptReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyVerifiedProviderAttemptSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyVerifiedProviderAttemptSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineProviderAttemptReviewTest extends TestCase
{
    private function scope(string $actor = 'reviewer'): TenantContext
    {
        return new TenantContext('org', 'workspace', 'brand', $actor);
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T16:00:00+00:00');
    }

    private function attempt(string $id = 'op-one'): array
    {
        return [
            'operation_id' => $id, 'idempotency_key' => 'idem-'.$id,
            'receipt_sha256' => str_repeat('b', 64),
            'attempted_at_unix' => $this->at()->getTimestamp() - 40,
            'outcome' => 'confirmed_not_applied',
            'verified_independently' => true, 'late' => false,
            'callback_count' => 1, 'external_cost_minor' => 0,
            'irreversible' => false,
        ];
    }

    private function evidence(array $attempts, bool $complete = true): array
    {
        $data = [
            'tenant' => $this->scope()->toArray(),
            'run_id' => 'run-one',
            'snapshot_sha256' => str_repeat('a', 64),
            'source_manifest_sha256' => str_repeat('0', 64),
            'captured_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 60,
            'complete' => $complete,
            'attempts' => $attempts,
        ];

        return $this->rebind($data);
    }

    private function rebind(array $data): array
    {
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

    private function review(?array $record, string $actor = 'reviewer'): array
    {
        $source = $this->createMock(BoundedAutonomyVerifiedProviderAttemptSource::class);
        $source->method('latest')->willReturn($record);

        return (new BoundedAutonomyOfflineProviderAttemptReview($source))->inspect(
            $this->scope($actor), 'run-one', str_repeat('a', 64), $this->at(),
        );
    }

    public function test_absent_attestation_is_held_without_external_authority(): void
    {
        $r = (new BoundedAutonomyOfflineProviderAttemptReview(
            new DenyingBoundedAutonomyVerifiedProviderAttemptSource,
        ))->inspect($this->scope(), 'run-one', str_repeat('a', 64), $this->at());

        self::assertSame('held_offline', $r['status']);
        self::assertSame('provider_attempt_source_unavailable', $r['reason_code']);
        self::assertFalse($r['execution_authorized']);
    }

    public function test_all_independently_verified_negative_attempts_are_review_only(): void
    {
        $r = $this->review($this->evidence([$this->attempt(), $this->attempt('op-two')]));

        self::assertSame('offline_no_effect_reconciliation_candidate', $r['status']);
        self::assertSame(2, $r['verified_attempt_count']);
        self::assertFalse($r['rollback_performed']);
        self::assertFalse($r['refund_authorized']);
        self::assertFalse($r['retry_authorized']);
        self::assertFalse($r['execution_authorized']);
        self::assertFalse($r['promotion_authorized']);
    }

    public function test_incomplete_unknown_applied_late_duplicate_and_costly_attempts_hold(): void
    {
        $base = $this->attempt();
        $cases = [
            [$this->evidence([]), 'provider_attempt_coverage_incomplete'],
            [$this->evidence([$base], false), 'provider_attempt_coverage_incomplete'],
            [$this->evidence([array_replace($base, ['outcome' => 'unknown'])]), 'unverified_provider_attempt'],
            [$this->evidence([array_replace($base, ['verified_independently' => false])]), 'unverified_provider_attempt'],
            [$this->evidence([array_replace($base, ['outcome' => 'confirmed_applied'])]), 'irreversible_provider_attempt_requires_manual_recovery'],
            [$this->evidence([array_replace($base, ['irreversible' => true])]), 'irreversible_provider_attempt_requires_manual_recovery'],
            [$this->evidence([array_replace($base, ['late' => true])]), 'late_or_duplicate_provider_callback'],
            [$this->evidence([array_replace($base, ['callback_count' => 2])]), 'late_or_duplicate_provider_callback'],
            [$this->evidence([array_replace($base, ['external_cost_minor' => 1])]), 'provider_cost_requires_independent_settlement'],
            [$this->evidence([$base, $base]), 'duplicate_provider_attempt_identity'],
            [$this->evidence([$base, array_replace($base, ['operation_id' => 'op-two'])]), 'duplicate_provider_attempt_identity'],
        ];
        foreach ($cases as [$record, $reason]) {
            $r = $this->review($record);
            self::assertSame('held_offline', $r['status']);
            self::assertSame($reason, $r['reason_code']);
            self::assertFalse($r['rollback_performed']);
            self::assertFalse($r['execution_authorized']);
        }
    }

    public function test_source_manifest_scope_and_unsupported_fields_cannot_be_forged(): void
    {
        $good = $this->evidence([$this->attempt()]);
        $attempt = $this->attempt();
        $invalid = [
            array_replace($good, ['source_manifest_sha256' => str_repeat('f', 64)]),
            array_replace($good, ['complete' => false]),
            array_replace($good, ['expires_at_unix' => $this->at()->getTimestamp() + 120]),
            $this->rebind(array_replace($good, ['tenant' => $this->scope('other')->toArray()])),
            $this->rebind(array_replace($good, ['run_id' => 'wrong-run'])),
            $this->rebind(array_replace($good, ['snapshot_sha256' => str_repeat('f', 64)])),
            array_replace($good, ['external_execution_authorized' => true]),
            $this->rebind(array_replace($good, ['captured_at_unix' => $this->at()->getTimestamp() + 1])),
            $this->rebind(array_replace($good, ['expires_at_unix' => $this->at()->getTimestamp()])),
            $this->evidence([array_replace($attempt, ['receipt_sha256' => 'invalid'])]),
            $this->evidence([array_replace($attempt, ['callback_count' => -1])]),
            $this->evidence([array_replace($attempt, ['attempted_at_unix' => $this->at()->getTimestamp() - 3601])]),
            $this->evidence([array_replace($attempt, ['attempted_at_unix' => -1])]),
            $this->evidence([array_replace($attempt, ['promote_now' => true])]),
        ];
        foreach ($invalid as $record) {
            try {
                $this->review($record);
                self::fail('Forged provider attempt evidence accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
