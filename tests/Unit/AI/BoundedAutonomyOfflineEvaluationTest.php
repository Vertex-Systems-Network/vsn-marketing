<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineEvaluation;
use App\Modules\AI\Application\BoundedAutonomyOfflineReceipt;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyObservationSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyObservationSource;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Audit\Domain\Contracts\AuditEventRepository;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdempotencyRepository;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Core\Domain\Idempotency\IdempotencyClaim;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineEvaluationTest extends TestCase
{
    private function fixture(): array
    {
        $store = (object) ['rows' => [], 'claims' => 0];
        $facts = (object) ['value' => null, 'calls' => 0];
        $repo = $this->createMock(IdempotencyRepository::class);
        $repo->method('claim')->willReturnCallback(static function (string $workspaceId, string $scope, string $key) use ($store): IdempotencyClaim {
            $identity = $workspaceId.':'.$scope.':'.$key;
            if (isset($store->rows[$identity])) {
                return new IdempotencyClaim(IdempotencyClaim::COMPLETED, $store->rows[$identity]);
            }
            $store->claims++;

            return new IdempotencyClaim(IdempotencyClaim::ACQUIRED);
        });
        $repo->method('complete')->willReturnCallback(static function (string $workspaceId, string $scope, string $key, array $result) use ($store): void {
            $store->rows[$workspaceId.':'.$scope.':'.$key] = $result;
        });
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn($this->at());
        $ids = $this->createMock(IdentifierGenerator::class);
        $ids->method('next')->willReturn('audit-offline');
        $auditEvents = $this->createMock(AuditEventRepository::class);
        $idempotency = new IdempotentExecutor($repo, new AuditRecorder($clock, $ids, $auditEvents));
        $source = $this->createMock(BoundedAutonomyObservationSource::class);
        $source->method('verifiedCount')->willReturnCallback(static function (TenantContext $scope, string $sourceId, string $metricId, DateTimeImmutable $at) use ($facts): ?array {
            $facts->calls++;

            return $facts->value;
        });
        $preview = new BoundedAutonomyPreview(
            ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
            ['source-1'],
            ['verified_conversions'],
        );
        $receipt = new BoundedAutonomyOfflineReceipt($preview, $idempotency);

        return [new BoundedAutonomyOfflineEvaluation($receipt, $source, $idempotency), $facts, $store];
    }

    private function scope(): TenantContext
    {
        return new TenantContext('org', 'workspace', null, 'operator');
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T00:00:00+00:00');
    }

    private function goal(): array
    {
        return [
            'workspace_id' => 'workspace',
            'brand_id' => null,
            'policy_version' => 'v1',
            'purpose' => 'campaign_optimization',
            'metric_id' => 'verified_conversions',
            'target_count' => 12,
            'expires_at_unix' => 1791507600,
        ];
    }

    private function actions(): array
    {
        return [[
            'tool_id' => 'analytics_read',
            'arguments_sha256' => str_repeat('a', 64),
            'source_ids' => ['source-1'],
            'reason_code' => 'metric_review',
        ]];
    }

    private function fact(int $count = 16): array
    {
        return [
            'workspace_id' => 'workspace',
            'brand_id' => null,
            'source_id' => 'source-1',
            'metric_id' => 'verified_conversions',
            'count' => $count,
            'observed_at_unix' => $this->at()->getTimestamp(),
            'evidence_sha256' => str_repeat('f', 64),
        ];
    }

    public function test_missing_independent_evidence_stays_held_without_authorization_or_evaluation_receipt(): void
    {
        [$runtime, $facts, $store] = $this->fixture();
        $result = $runtime->evaluate($this->scope(), 'run-1', $this->goal(), $this->actions(), 'source-1', $this->at());
        self::assertSame('awaiting_verified_observation', $result['status']);
        self::assertFalse($result['execution_authorized']);
        self::assertFalse($result['promotion_authorized']);
        self::assertSame('no_independently_verified_evidence', $result['reason_code']);
        self::assertSame(1, $facts->calls);
        self::assertCount(1, $store->rows);
    }

    public function test_verified_aggregate_only_produces_replay_safe_operator_review_with_no_causal_claim(): void
    {
        [$runtime, $facts, $store] = $this->fixture();
        $facts->value = $this->fact();
        $first = $runtime->evaluate($this->scope(), 'run-1', $this->goal(), $this->actions(), 'source-1', $this->at());
        $again = $runtime->evaluate($this->scope(), 'run-1', $this->goal(), $this->actions(), 'source-1', $this->at());
        self::assertSame($first, $again);
        self::assertSame('evaluated_offline', $first['status']);
        self::assertSame('target_met_operator_review', $first['decision']);
        self::assertFalse($first['causal_lift_proven']);
        self::assertFalse($first['execution_authorized']);
        self::assertFalse($first['promotion_authorized']);
        self::assertSame(16, $first['observed_count']);
        self::assertSame(2, $store->claims);
        self::assertCount(2, $store->rows);
    }

    public function test_below_target_holds_and_changed_evidence_cannot_replay_the_same_run(): void
    {
        [$runtime, $facts] = $this->fixture();
        $facts->value = $this->fact(2);
        $first = $runtime->evaluate($this->scope(), 'run-1', $this->goal(), $this->actions(), 'source-1', $this->at());
        self::assertSame('hold_below_target', $first['decision']);
        $facts->value = $this->fact(16);
        $this->expectException(InvalidArgumentException::class);
        $runtime->evaluate($this->scope(), 'run-1', $this->goal(), $this->actions(), 'source-1', $this->at());
    }

    public function test_untrusted_source_and_malformed_evidence_fail_closed(): void
    {
        $invalid = [
            ['workspace_id' => 'foreign'],
            ['metric_id' => 'invented'],
            ['source_id' => 'unregistered'],
            ['count' => -1],
            ['count' => '16'],
            ['observed_at_unix' => $this->at()->getTimestamp() + 1],
            ['observed_at_unix' => $this->at()->getTimestamp() - 86401],
            ['evidence_sha256' => 'forged'],
            ['model_self_approved' => true],
        ];
        foreach ($invalid as $patch) {
            [$runtime, $facts, $store] = $this->fixture();
            $facts->value = array_replace($this->fact(), $patch);
            try {
                $runtime->evaluate($this->scope(), 'run-1', $this->goal(), $this->actions(), 'source-1', $this->at());
                self::fail('Unverified or malformed observation accepted.');
            } catch (InvalidArgumentException) {
                self::assertCount(1, $store->rows);
            }
        }
        [$runtime, $facts] = $this->fixture();
        $this->expectException(InvalidArgumentException::class);
        try {
            $runtime->evaluate($this->scope(), 'run-1', $this->goal(), $this->actions(), 'outside-source', $this->at());
        } finally {
            self::assertSame(0, $facts->calls);
        }
    }

    public function test_default_source_denies_claims_without_contacting_any_provider(): void
    {
        $denier = new DenyingBoundedAutonomyObservationSource;
        self::assertNull($denier->verifiedCount($this->scope(), 'source-1', 'verified_conversions', $this->at()));
    }
}
