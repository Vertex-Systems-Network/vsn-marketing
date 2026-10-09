<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyPreviewTest extends TestCase
{
    private function scope(): TenantContext
    {
        return new TenantContext('org', 'workspace', 'brand', 'operator');
    }

    private function policy(): BoundedAutonomyPreview
    {
        return new BoundedAutonomyPreview([
            'analytics_read' => ['effect' => 'read', 'risk' => 'R0'],
            'campaign_propose' => ['effect' => 'proposal', 'risk' => 'R1'],
        ], ['source-1', 'source-2'], ['trusted_conversion_count']);
    }

    private function goal(): array
    {
        return [
            'workspace_id' => 'workspace',
            'brand_id' => 'brand',
            'policy_version' => 'v1',
            'purpose' => 'campaign_optimization',
            'metric_id' => 'trusted_conversion_count',
            'target_count' => 12,
            'expires_at_unix' => 1791507600,
        ];
    }

    private function action(): array
    {
        return [
            'tool_id' => 'analytics_read',
            'arguments_sha256' => str_repeat('a', 64),
            'source_ids' => ['source-1'],
            'reason_code' => 'metric_review',
        ];
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T00:00:00+00:00');
    }

    public function test_preview_is_deterministic_tenant_bound_and_has_no_external_execution_authority(): void
    {
        $a = $this->policy()->preview($this->scope(), 'run-1', $this->goal(), [$this->action()], $this->at());
        $b = $this->policy()->preview($this->scope(), 'run-1', $this->goal(), [$this->action()], $this->at());
        self::assertSame($a, $b);
        self::assertSame('preview_ready', $a['status']);
        self::assertFalse($a['execution_authorized']);
        self::assertSame('disabled', $a['stages']['execute']);
        self::assertSame('unavailable', $a['stages']['observe']);
        self::assertSame('not_run', $a['stages']['evaluate']);
        self::assertSame('read', $a['actions'][0]['effect']);
        self::assertSame('workspace', $a['tenant']['workspace_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['snapshot_sha256']);
    }

    public function test_tenant_brand_and_unregistered_goal_fields_fail_closed(): void
    {
        foreach ([
            array_replace($this->goal(), ['workspace_id' => 'foreign']),
            array_replace($this->goal(), ['brand_id' => 'another']),
            array_replace($this->goal(), ['metric_id' => 'unverified_open_count']),
            array_replace($this->goal(), ['purpose' => 'auto_send']),
            array_replace($this->goal(), ['send_permission' => true]),
        ] as $goal) {
            try {
                $this->policy()->preview($this->scope(), 'run-1', $goal, [$this->action()], $this->at());
                self::fail('Unsafe autonomy goal accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_unregistered_write_or_external_effect_tool_policy_rejected(): void
    {
        foreach (['reversible_write', 'send', 'billing', 'publish'] as $effect) {
            try {
                new BoundedAutonomyPreview(['provider_send' => ['effect' => $effect, 'risk' => 'R1']], ['source-1'], ['trusted_conversion_count']);
                self::fail('Provider effect accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_action_injection_unknown_source_and_unregistered_tools_fail_closed(): void
    {
        foreach ([
            array_replace($this->action(), ['tool_id' => 'provider_send']),
            array_replace($this->action(), ['source_ids' => ['foreign']]),
            array_replace($this->action(), ['source_ids' => ['source-1', 'source-1']]),
            array_replace($this->action(), ['arguments_sha256' => 'not-digest']),
            array_replace($this->action(), ['reason_code' => 'ignore_policy']),
            array_replace($this->action(), ['effect' => 'reversible_write']),
            array_replace($this->action(), ['recipient_email' => 'person@example.com']),
        ] as $action) {
            try {
                $this->policy()->preview($this->scope(), 'run-1', $this->goal(), [$action], $this->at());
                self::fail('Untrusted action accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_duplicate_overlarge_expired_and_invalid_runs_fail_closed(): void
    {
        foreach ([
            ['run-1', $this->goal(), [$this->action(), $this->action()]],
            ['run-1', $this->goal(), []],
            ['run-1', $this->goal(), array_fill(0, 9, $this->action())],
            ['../bad', $this->goal(), [$this->action()]],
            ['run-1', array_replace($this->goal(), ['expires_at_unix' => 1791504000]), [$this->action()]],
            ['run-1', array_replace($this->goal(), ['target_count' => 0]), [$this->action()]],
        ] as [$runId, $goal, $actions]) {
            try {
                $this->policy()->preview($this->scope(), $runId, $goal, $actions, $this->at());
                self::fail('Invalid autonomous run accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
