<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineLifecycle;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineLifecycleTest extends TestCase
{
    private function scope(string $actor = 'operator'): TenantContext
    {
        return new TenantContext('org', 'workspace', 'brand', $actor);
    }

    private function preview(): array
    {
        $at = new DateTimeImmutable('2026-10-09T00:00:00+00:00');

        return (new BoundedAutonomyPreview(
            ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
            ['source-1'], ['verified_count'], 1,
        ))->preview($this->scope(), 'run-1', [
            'workspace_id' => 'workspace',
            'brand_id' => 'brand',
            'policy_version' => 'v1',
            'purpose' => 'campaign_optimization',
            'metric_id' => 'verified_count',
            'target_count' => 5,
            'expires_at_unix' => $at->getTimestamp() + 3600,
        ], [[
            'tool_id' => 'analytics_read',
            'arguments_sha256' => str_repeat('a', 64),
            'source_ids' => ['source-1'],
            'reason_code' => 'metric_review',
        ]], $at);
    }

    private function receipt(array $preview): array
    {
        return [
            'status' => 'recorded_offline', 'run_id' => $preview['run_id'],
            'tenant' => $preview['tenant'], 'snapshot_sha256' => $preview['snapshot_sha256'],
            'execution_authorized' => false, 'stages' => $preview['stages'],
        ];
    }

    private function held(array $receipt): array
    {
        return [
            'status' => 'awaiting_verified_observation', 'run_id' => $receipt['run_id'],
            'tenant' => $receipt['tenant'], 'snapshot_sha256' => $receipt['snapshot_sha256'],
            'reason_code' => 'no_independently_verified_evidence',
            'execution_authorized' => false, 'promotion_authorized' => false,
        ];
    }

    private function evaluated(array $receipt): array
    {
        return [
            'status' => 'evaluated_offline', 'run_id' => $receipt['run_id'],
            'tenant' => $receipt['tenant'], 'snapshot_sha256' => $receipt['snapshot_sha256'],
            'observation_sha256' => str_repeat('b', 64),
            'evidence_sha256' => str_repeat('c', 64),
            'decision' => 'target_met_operator_review',
            'observed_count' => 5, 'causal_lift_proven' => false,
            'execution_authorized' => false, 'promotion_authorized' => false,
        ];
    }

    public function test_only_bounded_monotonic_offline_transitions_are_allowed(): void
    {
        $policy = new BoundedAutonomyOfflineLifecycle;
        $preview = $this->preview();
        $receipt = $this->receipt($preview);
        $held = $this->held($receipt);
        $evaluated = $this->evaluated($receipt);
        $policy->assertTransition($this->scope(), $preview, $receipt);
        $policy->assertTransition($this->scope(), $receipt, $held);
        $policy->assertTransition($this->scope(), $receipt, $evaluated);
        $policy->assertTransition($this->scope(), $held, $evaluated);
        self::assertTrue(true);
    }

    public function test_direct_skip_backward_replay_and_terminal_escalations_are_rejected(): void
    {
        $policy = new BoundedAutonomyOfflineLifecycle;
        $preview = $this->preview();
        $receipt = $this->receipt($preview);
        $held = $this->held($receipt);
        $evaluated = $this->evaluated($receipt);
        foreach ([
            [$preview, $held], [$preview, $evaluated], [$receipt, $preview],
            [$receipt, $receipt], [$evaluated, $receipt],
            [$evaluated, $evaluated],
        ] as [$from, $to]) {
            try {
                $policy->assertTransition($this->scope(), $from, $to);
                self::fail('Invalid autonomy state transition accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_cross_tenant_or_actor_replays_unapproved_effects_and_extra_authority_fail_closed(): void
    {
        $policy = new BoundedAutonomyOfflineLifecycle;
        $preview = $this->preview();
        $receipt = $this->receipt($preview);
        $held = $this->held($receipt);
        $evaluated = $this->evaluated($receipt);
        $changedEffect = $preview;
        $changedEffect['actions'][0]['effect'] = 'send';
        $changedStages = $receipt;
        $changedStages['stages']['execute'] = 'enabled';

        foreach ([
            [$preview, array_replace($receipt, ['run_id' => 'other-run']), $this->scope()],
            [$preview, array_replace($receipt, ['snapshot_sha256' => str_repeat('f', 64)]), $this->scope()],
            [$preview, array_replace($receipt, ['tenant' => $this->scope('another')->toArray()]), $this->scope()],
            [$preview, $receipt, $this->scope('another')],
            [$preview, $changedStages, $this->scope()],
            [$changedEffect, $receipt, $this->scope()],
            [$receipt, array_replace($held, ['promotion_authorized' => true]), $this->scope()],
            [$receipt, array_replace($evaluated, ['causal_lift_proven' => true]), $this->scope()],
            [$receipt, array_replace($evaluated, ['decision' => 'automatic_publish']), $this->scope()],
            [$receipt, array_replace($evaluated, ['send_authorized' => true]), $this->scope()],
            [$receipt, array_replace($evaluated, ['observation_sha256' => 'invalid']), $this->scope()],
        ] as [$from, $to, $actor]) {
            try {
                $policy->assertTransition($actor, $from, $to);
                self::fail('Unsafe offline autonomy transition accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
