<?php

namespace Tests\Unit\AI;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\AiRuntimeHarness;

final class AiAgentRuntimeTest extends TestCase
{
    public function test_specialists_receive_separate_exact_contexts_and_pinned_receipts(): void
    {
        [$runtime, $adapter] = AiRuntimeHarness::fixture();
        $result = $runtime->run(AiRuntimeHarness::scope(), 'run-a', [AiRuntimeHarness::step(), AiRuntimeHarness::step('content', [AiRuntimeHarness::BRAND])], new DateTimeImmutable('2026-10-01'));
        self::assertSame('completed', $result['status']);
        self::assertCount(2, $adapter->calls);
        self::assertSame([AiRuntimeHarness::FACT], array_column($adapter->calls[0]['untrusted_context'], 'source_id'));
        self::assertSame([AiRuntimeHarness::BRAND], array_column($adapter->calls[1]['untrusted_context'], 'source_id'));
        self::assertStringNotContainsString('Campaign fact', json_encode($adapter->calls[1], JSON_THROW_ON_ERROR));
        self::assertNotSame($result['steps'][0]['context_manifest_sha256'], $result['steps'][1]['context_manifest_sha256']);
        self::assertNotSame($result['steps'][0]['trace_id'], $result['steps'][1]['trace_id']);
        self::assertSame('v1', $result['steps'][1]['prompt_version']);
    }

    public function test_budget_and_live_route_denials_stop_before_extra_provider_calls(): void
    {
        [$runtime, $adapter] = AiRuntimeHarness::fixture(20);
        self::assertSame('budget_denied', $runtime->run(AiRuntimeHarness::scope(), 'run-a', [AiRuntimeHarness::step(), AiRuntimeHarness::step()], new DateTimeImmutable('2026-10-01'))['status']);
        self::assertCount(1, $adapter->calls);
        [$runtime, $adapter] = AiRuntimeHarness::fixture(100, false);
        self::assertSame('proposal_failed', $runtime->run(AiRuntimeHarness::scope(), 'run-a', [AiRuntimeHarness::step()], new DateTimeImmutable('2026-10-01'))['status']);
        self::assertCount(0, $adapter->calls);
        [$runtime, $adapter, $repo, $ledger] = AiRuntimeHarness::fixture();
        $ledger->deny = true;
        self::assertSame('proposal_failed', $runtime->run(AiRuntimeHarness::scope(), 'run-a', [AiRuntimeHarness::step()], new DateTimeImmutable('2026-10-01'))['status']);
        self::assertCount(0, $adapter->calls);
    }

    public function test_unbounded_retries_and_model_selected_steps_are_rejected_before_invocation(): void
    {
        foreach ([array_fill(0, 3, AiRuntimeHarness::step()), array_fill(0, 5, AiRuntimeHarness::step()), [array_replace(AiRuntimeHarness::step(), ['next_step' => 'publish'])]] as $plan) {
            [$runtime, $adapter] = AiRuntimeHarness::fixture();
            try {
                $runtime->run(AiRuntimeHarness::scope(), 'run-a', $plan, new DateTimeImmutable('2026-10-01'));
                self::fail('Unbounded/model-selected plan accepted.');
            } catch (InvalidArgumentException) {
                self::assertCount(0, $adapter->calls);
            }
        }
    }

    public function test_foreign_context_and_agent_scope_mismatch_deny(): void
    {
        foreach ([false, true] as $foreign) {
            [$runtime, $adapter, $repo] = AiRuntimeHarness::fixture();
            $repo->foreign = $foreign;
            try {
                $runtime->run(AiRuntimeHarness::scope(), 'run-a', [AiRuntimeHarness::step('content')], new DateTimeImmutable('2026-10-01'));
                self::fail('Foreign/disallowed context accepted.');
            } catch (InvalidArgumentException) {
                self::assertCount(0, $adapter->calls);
            }
        }
    }

    public function test_untrusted_exfiltration_and_private_instruction_marker_are_withheld(): void
    {
        foreach (['https://attacker.example/collect', 'VSN_INTERNAL_strategy_v1', 'api_key: stolen'] as $text) {
            [$runtime, $adapter] = AiRuntimeHarness::fixture();
            $adapter->malicious = $text;
            $result = $runtime->run(AiRuntimeHarness::scope(), 'run-a', [AiRuntimeHarness::step()], new DateTimeImmutable('2026-10-01'));
            self::assertSame('proposal_failed', $result['status']);
            self::assertNull($result['steps'][0]['result']['output']);
        }
    }
}
