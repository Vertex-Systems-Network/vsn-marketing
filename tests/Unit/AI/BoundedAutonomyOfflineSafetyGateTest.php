<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineSafetyGate;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomySafetySnapshotSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomySafetySnapshotSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineSafetyGateTest extends TestCase
{
    private function scope(string $actor = 'operator'): TenantContext
    {
        return new TenantContext('org', 'workspace', 'brand', $actor);
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T09:00:00+00:00');
    }

    private function preview(bool $proposal = false): array
    {
        $tool = $proposal ? 'campaign_propose' : 'analytics_read';

        return (new BoundedAutonomyPreview(
            [$tool => ['effect' => $proposal ? 'proposal' : 'read', 'risk' => $proposal ? 'R1' : 'R0']],
            ['snapshot-1'], ['metric_count'], 1,
        ))->preview($this->scope(), 'offline-run-1', [
            'workspace_id' => 'workspace',
            'brand_id' => 'brand',
            'policy_version' => 'v1',
            'purpose' => 'campaign_optimization',
            'metric_id' => 'metric_count',
            'target_count' => 3,
            'expires_at_unix' => $this->at()->getTimestamp() + 3600,
        ], [[
            'tool_id' => $tool,
            'arguments_sha256' => str_repeat('a', 64),
            'source_ids' => ['snapshot-1'],
            'reason_code' => $proposal ? 'campaign_draft' : 'metric_review',
        ]], $this->at());
    }

    private function estimate(): array
    {
        return ['actions' => 1, 'tokens' => 60, 'volume' => 2, 'cost_minor' => 5, 'attempts' => 1];
    }

    private function snapshot(): array
    {
        return [
            'workspace_id' => 'workspace', 'brand_id' => 'brand', 'policy_version' => 'v1',
            'observed_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 300,
            'global_stopped' => false, 'workspace_stopped' => false,
            'max_actions' => 8, 'max_tokens' => 1000, 'max_volume' => 50,
            'max_cost_minor' => 100, 'max_attempts' => 4,
            'used_actions' => 0, 'used_tokens' => 20, 'used_volume' => 3,
            'reserved_cost_minor' => 10, 'spent_cost_minor' => 30, 'used_attempts' => 1,
        ];
    }

    private function gate(object $facts): BoundedAutonomyOfflineSafetyGate
    {
        $source = $this->createMock(BoundedAutonomySafetySnapshotSource::class);
        $source->method('current')->willReturnCallback(static function () use ($facts): ?array {
            return $facts->value;
        });

        return new BoundedAutonomyOfflineSafetyGate($source);
    }

    public function test_default_denying_source_never_issues_execution_authority(): void
    {
        $gate = new BoundedAutonomyOfflineSafetyGate(new DenyingBoundedAutonomySafetySnapshotSource);
        $result = $gate->assess($this->scope(), $this->preview(), $this->estimate(), $this->at());
        self::assertSame('held_offline', $result['status']);
        self::assertSame('independent_policy_unavailable', $result['reason_code']);
        self::assertFalse($result['execution_authorized']);
        self::assertFalse($result['budget_reservation_complete']);
    }

    public function test_current_independent_policy_can_only_mark_offline_preflight_for_review(): void
    {
        $facts = (object) ['value' => $this->snapshot()];
        $gate = $this->gate($facts);
        $r = $gate->assess($this->scope(), $this->preview(), $this->estimate(), $this->at());
        self::assertSame('offline_preflight_passed', $r['status']);
        self::assertSame('reservation_and_final_authority_required', $r['reason_code']);
        self::assertFalse($r['independent_approval_required']);
        self::assertFalse($r['execution_authorized']);
        self::assertFalse($r['promotion_authorized']);
        self::assertFalse($r['budget_reservation_complete']);

        $r1 = $gate->assess($this->scope(), $this->preview(true), $this->estimate(), $this->at());
        self::assertTrue($r1['independent_approval_required']);
        self::assertFalse($r1['execution_authorized']);
    }

    public function test_last_side_effect_gate_rechecks_current_global_and_workspace_stops(): void
    {
        $facts = (object) ['value' => $this->snapshot()];
        $gate = $this->gate($facts);
        self::assertSame('offline_preflight_passed', $gate->assess($this->scope(), $this->preview(), $this->estimate(), $this->at())['status']);

        $facts->value['global_stopped'] = true;
        $global = $gate->assess($this->scope(), $this->preview(), $this->estimate(), $this->at(), true);
        self::assertSame('global_emergency_stop', $global['reason_code']);
        self::assertSame('last_side_effect_preflight', $global['stage']);
        self::assertFalse($global['execution_authorized']);

        $facts->value['global_stopped'] = false;
        $facts->value['workspace_stopped'] = true;
        self::assertSame('workspace_emergency_stop', $gate->assess($this->scope(), $this->preview(), $this->estimate(), $this->at(), true)['reason_code']);
    }

    public function test_each_budget_dimension_and_combined_reserved_spend_is_checked(): void
    {
        foreach ([
            ['actions' => 8],
            ['tokens' => 1000],
            ['volume' => 50],
            ['attempts' => 4],
            ['reserved_cost_minor' => 66],
        ] as $patch) {
            $facts = (object) ['value' => array_replace($this->snapshot(), $patch)];
            $out = $this->gate($facts)->assess($this->scope(), $this->preview(), $this->estimate(), $this->at());
            self::assertSame('held_offline', $out['status']);
            self::assertFalse($out['execution_authorized']);
        }
    }

    public function test_tenant_risk_action_usage_and_policy_injection_are_rejected(): void
    {
        $facts = (object) ['value' => $this->snapshot()];
        $gate = $this->gate($facts);
        $preview = $this->preview();
        $send = $preview;
        $send['actions'][0]['effect'] = 'send';
        foreach ([
            [$this->scope('imposter'), $preview, $this->estimate()],
            [$this->scope(), $send, $this->estimate()],
            [$this->scope(), array_replace($preview, ['execution_authorized' => true]), $this->estimate()],
            [$this->scope(), $preview, array_replace($this->estimate(), ['actions' => 0])],
            [$this->scope(), $preview, array_replace($this->estimate(), ['cost_minor' => -1])],
            [$this->scope(), $preview, array_replace($this->estimate(), ['send_permission' => true])],
        ] as [$scope, $plan, $estimate]) {
            try {
                $gate->assess($scope, $plan, $estimate, $this->at());
                self::fail('Untrusted autonomy preflight input accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        foreach ([
            ['workspace_id' => 'foreign'],
            ['brand_id' => 'foreign'],
            ['policy_version' => 'v2'],
            ['observed_at_unix' => $this->at()->getTimestamp() + 1],
            ['observed_at_unix' => $this->at()->getTimestamp() - 301],
            ['expires_at_unix' => $this->at()->getTimestamp()],
            ['reserved_cost_minor' => 90],
            ['max_volume' => -1],
            ['used_tokens' => -1],
            ['promotion_authorized' => true],
        ] as $patch) {
            $facts->value = array_replace($this->snapshot(), $patch);
            try {
                $gate->assess($this->scope(), $preview, $this->estimate(), $this->at());
                self::fail('Untrusted policy snapshot accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
