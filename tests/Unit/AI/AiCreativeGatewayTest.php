<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\AiContextAssembler;
use App\Modules\AI\Application\AiCreativeCatalog;
use App\Modules\AI\Application\AiCreativeDraftGenerator;
use App\Modules\AI\Domain\AiContextSanitizer;
use App\Modules\AI\Domain\Contracts\AiBudgetLedger;
use App\Modules\AI\Domain\Contracts\AiCircuitBreaker;
use App\Modules\AI\Domain\Contracts\AiContextPermission;
use App\Modules\AI\Domain\Contracts\AiContextRepository;
use App\Modules\AI\Domain\Contracts\AiCreativePolicyAuthority;
use App\Modules\AI\Domain\Contracts\AiCreativeProvider;
use App\Modules\AI\Domain\Contracts\AiTelemetryRecorder;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AiCreativeGatewayTest extends TestCase
{
    private const SOURCE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private function runFixture(string $capability = 'creative_text', bool $rights = true, bool $live = false, string $status = 'complete'): array
    {
        $scope = new TenantContext('org-a', 'workspace-a', 'brand-a', 'creator');
        $catalog = new AiCreativeCatalog(dirname(__DIR__, 3));
        $definition = $catalog->resolve($capability, 'v1');
        $repository = $this->createMock(AiContextRepository::class);
        $repository->method('fetch')->willReturn([['id' => self::SOURCE, 'workspace_id' => 'workspace-a',
            'brand_id' => 'brand-a', 'customer_id' => null, 'run_id' => null, 'source_kind' => 'brand_guideline',
            'classification' => 'public', 'permission' => 'template.create', 'content' => 'Use a calm brand tone.',
            'revision' => 'v1', 'provenance_reference' => 'brand-policy', 'expires_at' => '2026-10-02 00:00:00']]);
        $permissions = $this->createMock(AiContextPermission::class);
        $permissions->method('allows')->willReturn(true);
        $authority = $this->createMock(AiCreativePolicyAuthority::class);
        $authority->expects(self::once())->method('allowsInput')->with($scope,
            self::callback(static fn (string $hash): bool => (bool) preg_match('/^[a-f0-9]{64}$/D', $hash)), 'rights-a')->willReturn($rights);
        $calls = 0;
        $provider = $this->createMock(AiCreativeProvider::class);
        $provider->method('generate')->willReturnCallback(function (array $request) use (&$calls, $capability, $status): array {
            $calls++;
            self::assertSame('workspace-a', $request['workspace_id']);
            self::assertSame('Draft a calm announcement.', $request['untrusted_brief']);
            self::assertSame('untrusted_data', $request['untrusted_context'][0]['trust']);

            return ['status' => $status, 'cost_minor' => 2, 'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
                'schema_id' => 'creative_candidate.v1', 'provider_request_id' => 'offline-request-a',
                'output' => ['workspace_id' => 'workspace-a', 'reference_ids' => [self::SOURCE], 'text' => 'A calm announcement.',
                    'media' => $capability === 'creative_image' ? [['mime_type' => 'image/png', 'bytes' => base64_decode(AiCreativeDraftPolicyTest::PNG, true)]] : []]];
        });
        $budget = $this->createMock(AiBudgetLedger::class);
        $budget->expects($rights && ! $live ? self::once() : self::never())->method('reserve')->willReturn(true);
        $budget->expects($rights && ! $live ? self::once() : self::never())->method('settle')->with('workspace-a', self::anything(), 2);
        $circuit = $this->createMock(AiCircuitBreaker::class);
        $circuit->method('allows')->willReturn(true);
        $telemetry = $this->createMock(AiTelemetryRecorder::class);
        $telemetry->method('begin')->willReturn(true);
        $route = ['id' => 'offline-creative', 'version' => 'v1', 'adapter_id' => 'fixture',
            'credential_reference' => 'offline-no-credential', 'status' => 'active', 'workspaces' => ['workspace-a'],
            'data_regions' => ['eu'], 'data_classes' => ['public'], 'capabilities' => [$capability, 'usage_telemetry'],
            'output_schemas' => ['creative_candidate.v1'], 'tool_ids' => [], 'risk_tiers' => ['R1'], 'max_reservation_minor' => 10,
            'evidence_kind' => $live ? 'live' : 'offline_contract', 'creative_policy_sha256' => $definition['policy_sha256']];
        $generator = new AiCreativeDraftGenerator($catalog, new AiContextAssembler($repository, $permissions, new AiContextSanitizer),
            $authority, [$route], ['fixture' => $provider], $budget, $circuit, $telemetry, 'eu', 20);
        try {
            $result = $generator->generate($scope, $capability, 'v1', 'Draft a calm announcement.', [self::SOURCE], 'rights-a', 'creative-trace-a', new DateTimeImmutable('2026-10-01'));
        } catch (InvalidArgumentException $error) {
            self::assertFalse($rights);
            $result = ['status' => 'input_denied'];
        }

        return [$result, $calls];
    }

    public function test_portable_text_and_image_protocol_use_actual_gateway_accounting_and_draft_validation(): void
    {
        foreach (['creative_text', 'creative_image'] as $capability) {
            [$result, $calls] = $this->runFixture($capability);
            self::assertSame('complete', $result['status']);
            self::assertSame('validated', $result['validation_status']);
            self::assertSame('draft', $result['output']['status']);
            self::assertSame(2, $result['cost_minor']);
            self::assertSame(1, $calls);
        }
    }

    public function test_missing_rights_and_unapproved_live_routes_never_invoke_provider(): void
    {
        [$result, $calls] = $this->runFixture('creative_text', false);
        self::assertSame('input_denied', $result['status']);
        self::assertSame(0, $calls);
        [$result, $calls] = $this->runFixture('creative_image', true, true);
        self::assertSame('route_unavailable', $result['status']);
        self::assertSame(0, $calls);
    }

    public function test_refusal_incomplete_and_cancelled_statuses_account_usage_and_withhold_draft(): void
    {
        foreach (['refused', 'incomplete', 'cancelled'] as $status) {
            [$result, $calls] = $this->runFixture('creative_text', true, false, $status);
            self::assertSame($status, $result['status']);
            self::assertNull($result['output']);
            self::assertSame(1, $calls);
        }
    }
}
