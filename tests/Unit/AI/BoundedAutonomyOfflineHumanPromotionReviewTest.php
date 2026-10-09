<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineHumanPromotionReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyCanaryHumanDecisionSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyCanaryHumanDecisionSource;
use App\Modules\Experiments\Domain\ExperimentAnalysisPlan;
use App\Modules\Experiments\Domain\ExperimentPlan;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineHumanPromotionReviewTest extends TestCase
{
    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    }

    private function actor(string $id = 'operator'): TenantContext
    {
        return new TenantContext('org', 'workspace', null, $id);
    }

    private function experiment(): ExperimentPlan
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

    private function joined(): array
    {
        return [
            'status' => 'offline_joined_operator_review_candidate',
            'reason_code' => 'independent_human_promotion_gate_required',
            'experiment_id' => $this->experiment()->id,
            'cohort_receipt_id' => '11111111-2222-4333-8444-555555555555',
            'plan_sha256' => $this->experiment()->fingerprint(),
            'analysis_sha256' => $this->analysis()->fingerprint(),
            'outcome_manifest_sha256' => str_repeat('a', 64),
            'execution_authorized' => false,
            'promotion_authorized' => false,
            'external_outcome_proven' => false,
        ];
    }

    private function decision(): array
    {
        return [
            'tenant' => $this->actor()->toArray(),
            'experiment_id' => $this->experiment()->id,
            'plan_sha256' => $this->experiment()->fingerprint(),
            'analysis_sha256' => $this->analysis()->fingerprint(),
            'cohort_receipt_id' => $this->joined()['cohort_receipt_id'],
            'outcome_manifest_sha256' => str_repeat('a', 64),
            'decision_id' => 'approval-1',
            'deciding_actor_id' => 'owner',
            'outcome' => 'approved',
            'policy_version' => 'v1',
            'authenticated_human' => true,
            'current_permission_verified' => true,
            'observed_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 300,
        ];
    }

    private function reviewer(object $record): BoundedAutonomyOfflineHumanPromotionReview
    {
        $source = $this->createMock(BoundedAutonomyCanaryHumanDecisionSource::class);
        $source->method('latest')->willReturnCallback(static function () use ($record): ?array {
            return $record->value;
        });

        return new BoundedAutonomyOfflineHumanPromotionReview($source);
    }

    public function test_default_source_fails_closed_without_promotion(): void
    {
        $r = (new BoundedAutonomyOfflineHumanPromotionReview(new DenyingBoundedAutonomyCanaryHumanDecisionSource))
            ->inspect($this->actor(), $this->experiment(), $this->analysis(), $this->joined(), $this->at());
        self::assertSame('held_offline', $r['status']);
        self::assertSame('independent_human_decision_unavailable', $r['reason_code']);
        self::assertFalse($r['promotion_authorized']);
        self::assertFalse($r['execution_authorized']);
    }

    public function test_exact_current_independent_human_decision_only_enables_offline_review(): void
    {
        $record = (object) ['value' => $this->decision()];
        $result = $this->reviewer($record)->inspect(
            $this->actor(), $this->experiment(), $this->analysis(), $this->joined(), $this->at(),
        );
        self::assertSame('offline_human_review_evidence_ready', $result['status']);
        self::assertSame('approval-1', $result['decision_id']);
        self::assertSame('separate_atomic_promotion_and_provider_gates_required', $result['reason_code']);
        self::assertFalse($result['execution_authorized']);
        self::assertFalse($result['promotion_authorized']);
        self::assertFalse($result['external_outcome_proven']);
    }

    public function test_untrusted_scoring_or_escalated_model_output_is_rejected(): void
    {
        $record = (object) ['value' => $this->decision()];
        $reviewer = $this->reviewer($record);
        foreach ([
            ['execution_authorized' => true],
            ['promotion_authorized' => true],
            ['external_outcome_proven' => true],
            ['outcome_manifest_sha256' => 'unknown'],
            ['analysis_sha256' => str_repeat('f', 64)],
            ['allow_publish' => true],
        ] as $patch) {
            try {
                $reviewer->inspect($this->actor(), $this->experiment(), $this->analysis(),
                    array_replace($this->joined(), $patch), $this->at());
                self::fail('Untrusted canary scoring accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_independent_approval_revocation_identity_and_scope_drift_hold(): void
    {
        $record = (object) ['value' => $this->decision()];
        $reviewer = $this->reviewer($record);
        foreach ([
            ['outcome' => 'revoked'], ['outcome' => 'rejected'],
            ['deciding_actor_id' => 'operator'],
            ['authenticated_human' => false],
            ['current_permission_verified' => false],
            ['tenant' => (new TenantContext('other', 'workspace', null, 'operator'))->toArray()],
            ['plan_sha256' => str_repeat('f', 64)],
            ['outcome_manifest_sha256' => str_repeat('f', 64)],
            ['cohort_receipt_id' => 'wrong-receipt'],
        ] as $patch) {
            $record->value = array_replace($this->decision(), $patch);
            $result = $reviewer->inspect($this->actor(), $this->experiment(),
                $this->analysis(), $this->joined(), $this->at());
            self::assertSame('held_offline', $result['status']);
            self::assertFalse($result['promotion_authorized']);
        }
    }

    public function test_expired_forged_future_decisions_fail_closed(): void
    {
        $record = (object) ['value' => $this->decision()];
        $reviewer = $this->reviewer($record);
        foreach ([
            ['observed_at_unix' => $this->at()->getTimestamp() + 1],
            ['observed_at_unix' => $this->at()->getTimestamp() - 301],
            ['expires_at_unix' => $this->at()->getTimestamp()],
            ['deciding_actor_id' => ''],
            ['policy_version' => 'v2'],
            ['unknown_authority' => true],
        ] as $patch) {
            $record->value = array_replace($this->decision(), $patch);
            try {
                $reviewer->inspect($this->actor(), $this->experiment(), $this->analysis(),
                    $this->joined(), $this->at());
                self::fail('Forged human decision accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
