<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineApprovalReview;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyApprovalSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyApprovalSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineApprovalReviewTest extends TestCase
{
    private function scope(string $actor = 'requester'): TenantContext
    {
        return new TenantContext('org', 'workspace', 'brand', $actor);
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    }

    private function preview(): array
    {
        $at = $this->at();
        return (new BoundedAutonomyPreview(
            ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
            ['source-1'], ['count'],
        ))->preview($this->scope(), 'run-1', [
            'workspace_id' => 'workspace', 'brand_id' => 'brand',
            'policy_version' => 'v1', 'purpose' => 'campaign_optimization',
            'metric_id' => 'count', 'target_count' => 1,
            'expires_at_unix' => $at->getTimestamp() + 3600,
        ], [[
            'tool_id' => 'analytics_read', 'arguments_sha256' => str_repeat('a', 64),
            'source_ids' => ['source-1'], 'reason_code' => 'metric_review',
        ]], $at);
    }

    private function binding(): array
    {
        return [
            'audience_sha256' => str_repeat('b', 64),
            'content_sha256' => str_repeat('c', 64),
            'destination_sha256' => str_repeat('d', 64),
            'max_cost_minor' => 20, 'max_volume' => 10,
            'not_before_unix' => $this->at()->getTimestamp() - 60,
            'expires_at_unix' => $this->at()->getTimestamp() + 300,
        ];
    }

    private function decision(): array
    {
        return array_merge([
            'decision_id' => 'approval-1',
            'workspace_id' => 'workspace',
            'brand_id' => 'brand',
            'run_id' => 'run-1',
            'snapshot_sha256' => $this->preview()['snapshot_sha256'],
            'policy_version' => 'v1',
        ], $this->binding(), [
            'approved_at_unix' => $this->at()->getTimestamp() - 30,
            'approver_id' => 'owner',
            'outcome' => 'approved',
        ]);
    }

    private function verifier(object $record): BoundedAutonomyOfflineApprovalReview
    {
        $source = $this->createMock(BoundedAutonomyApprovalSource::class);
        $source->method('latest')->willReturnCallback(static function () use ($record): ?array {
            return $record->value;
        });

        return new BoundedAutonomyOfflineApprovalReview($source);
    }

    public function test_missing_source_fails_closed_without_authority(): void
    {
        $r = (new BoundedAutonomyOfflineApprovalReview(new DenyingBoundedAutonomyApprovalSource))
            ->inspect($this->scope(), $this->preview(), $this->binding(), $this->at());
        self::assertSame('approval_held_offline', $r['status']);
        self::assertSame('independent_approval_unavailable', $r['reason_code']);
        self::assertFalse($r['execution_authorized']);
    }

    public function test_independent_exact_plan_and_content_approval_is_operator_review_only(): void
    {
        $record = (object) ['value' => $this->decision()];
        $r = $this->verifier($record)->inspect($this->scope(), $this->preview(), $this->binding(), $this->at());
        self::assertSame('approval_matched_offline', $r['status']);
        self::assertSame('approval-1', $r['decision_id']);
        self::assertFalse($r['execution_authorized']);
        self::assertFalse($r['promotion_authorized']);
    }

    public function test_revocation_self_approval_foreign_scope_and_material_change_are_held(): void
    {
        foreach ([
            ['outcome' => 'revoked'], ['outcome' => 'rejected'],
            ['approver_id' => 'requester'],
            ['workspace_id' => 'foreign'], ['brand_id' => 'foreign'],
            ['run_id' => 'other-run'], ['snapshot_sha256' => str_repeat('f', 64)],
            ['policy_version' => 'v2'], ['max_cost_minor' => 21],
            ['content_sha256' => str_repeat('e', 64)],
            ['audience_sha256' => str_repeat('e', 64)],
            ['destination_sha256' => str_repeat('e', 64)],
            ['approved_at_unix' => $this->at()->getTimestamp() + 1],
            ['approved_at_unix' => $this->at()->getTimestamp() - 301],
        ] as $patch) {
            $record = (object) ['value' => array_replace($this->decision(), $patch)];
            $r = $this->verifier($record)->inspect($this->scope(), $this->preview(), $this->binding(), $this->at());
            self::assertSame('approval_held_offline', $r['status']);
            self::assertFalse($r['execution_authorized']);
        }
    }

    public function test_unknown_fields_forged_model_authority_and_invalid_expiry_are_denied(): void
    {
        $record = (object) ['value' => $this->decision()];
        $verify = $this->verifier($record);
        foreach ([
            array_replace($this->binding(), ['max_cost_minor' => -1]),
            array_replace($this->binding(), ['expires_at_unix' => $this->at()->getTimestamp()]),
            array_replace($this->binding(), ['ai_approval' => true]),
        ] as $invalid) {
            try {
                $verify->inspect($this->scope(), $this->preview(), $invalid, $this->at());
                self::fail('Invalid operator approval input accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([
            ['approver_id' => ''], ['model_self_approved' => true],
            ['approved_at_unix' => 'future'],
        ] as $patch) {
            $record->value = array_replace($this->decision(), $patch);
            try {
                $verify->inspect($this->scope(), $this->preview(), $this->binding(), $this->at());
                self::fail('Forged model authority accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
