<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineReceipt;
use App\Modules\AI\Application\BoundedAutonomyPreview;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditEventRepository;
use App\Modules\Core\Application\Idempotency\IdempotentExecutor;
use App\Modules\Core\Domain\Contracts\Clock;
use App\Modules\Core\Domain\Contracts\IdentifierGenerator;
use App\Modules\Core\Domain\Contracts\IdempotencyRepository;
use App\Modules\Core\Domain\Idempotency\IdempotencyClaim;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BoundedAutonomyOfflineReceiptTest extends TestCase
{
    private function fixture(): array
    {
        $repository = new class implements IdempotencyRepository
        {
            public array $receipts = [];

            public bool $concurrent = false;

            public int $acquisitions = 0;

            public function claim(string $workspaceId, string $scope, string $key): IdempotencyClaim
            {
                $identity = $workspaceId.':'.$scope.':'.$key;
                if ($this->concurrent) {
                    return new IdempotencyClaim(IdempotencyClaim::IN_PROGRESS);
                }
                if (isset($this->receipts[$identity])) {
                    return new IdempotencyClaim(IdempotencyClaim::COMPLETED, $this->receipts[$identity]);
                }
                $this->acquisitions++;

                return new IdempotencyClaim(IdempotencyClaim::ACQUIRED);
            }

            public function complete(string $workspaceId, string $scope, string $key, array $result): void
            {
                $this->receipts[$workspaceId.':'.$scope.':'.$key] = $result;
            }

            public function fail(string $workspaceId, string $scope, string $key, string $error): void {}
        };
        $events = (object) ['stored' => []];
        $auditRepository = $this->createMock(AuditEventRepository::class);
        $auditRepository->method('store')->willReturnCallback(static function (AuditEvent $event) use ($events): void {
            $events->stored[] = $event;
        });
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-10-09T00:00:00+00:00'));
        $identifiers = $this->createMock(IdentifierGenerator::class);
        $identifiers->method('next')->willReturn('event-1');
        $audit = new AuditRecorder($clock, $identifiers, $auditRepository);
        $previews = new BoundedAutonomyPreview(
            ['analytics_read' => ['effect' => 'read', 'risk' => 'R0']],
            ['trusted-source'],
            ['conversion_count'],
        );

        return [new BoundedAutonomyOfflineReceipt($previews, new IdempotentExecutor($repository, $audit)), $repository, $events];
    }

    private function scope(string $workspace = 'workspace', string $actor = 'operator'): TenantContext
    {
        return new TenantContext('org', $workspace, 'brand', $actor);
    }

    private function goal(string $workspace = 'workspace', int $target = 10): array
    {
        return [
            'workspace_id' => $workspace,
            'brand_id' => 'brand',
            'policy_version' => 'v1',
            'purpose' => 'campaign_optimization',
            'metric_id' => 'conversion_count',
            'target_count' => $target,
            'expires_at_unix' => 1791507600,
        ];
    }

    private function actions(): array
    {
        return [[
            'tool_id' => 'analytics_read',
            'arguments_sha256' => str_repeat('f', 64),
            'source_ids' => ['trusted-source'],
            'reason_code' => 'metric_review',
        ]];
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T00:00:00+00:00');
    }

    public function test_exact_replay_is_idempotent_and_never_authorizes_external_execution(): void
    {
        [$runtime, $repo, $audit] = $this->fixture();
        $first = $runtime->record($this->scope(), 'run-1', $this->goal(), $this->actions(), $this->at());
        $repeat = $runtime->record($this->scope(), 'run-1', $this->goal(), $this->actions(), $this->at());
        self::assertSame($first, $repeat);
        self::assertSame('recorded_offline', $first['status']);
        self::assertFalse($first['execution_authorized']);
        self::assertSame('disabled', $first['stages']['execute']);
        self::assertSame(1, $repo->acquisitions);
        self::assertCount(1, $audit->stored);
    }

    public function test_same_key_with_altered_goal_actor_or_policy_fails_closed(): void
    {
        [$runtime, $repo] = $this->fixture();
        $runtime->record($this->scope(), 'run-1', $this->goal(), $this->actions(), $this->at());
        foreach ([
            [$this->scope(), $this->goal('workspace', 11)],
            [$this->scope('workspace', 'other-actor'), $this->goal()],
            [$this->scope(), array_replace($this->goal(), ['policy_version' => 'v2'])],
        ] as [$scope, $goal]) {
            try {
                $runtime->record($scope, 'run-1', $goal, $this->actions(), $this->at());
                self::fail('Conflicting replay accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(1, $repo->acquisitions);
            }
        }
    }

    public function test_replay_key_remains_tenant_scoped(): void
    {
        [$runtime, $repo] = $this->fixture();
        $a = $runtime->record($this->scope(), 'same-run', $this->goal(), $this->actions(), $this->at());
        $b = $runtime->record($this->scope('workspace-b'), 'same-run', $this->goal('workspace-b'), $this->actions(), $this->at());
        self::assertNotSame($a['snapshot_sha256'], $b['snapshot_sha256']);
        self::assertSame(2, $repo->acquisitions);
    }

    public function test_in_progress_claim_or_tampered_receipt_is_rejected(): void
    {
        [$runtime, $repo] = $this->fixture();
        $repo->concurrent = true;
        try {
            $runtime->record($this->scope(), 'run-1', $this->goal(), $this->actions(), $this->at());
            self::fail('Concurrent in-progress replay accepted.');
        } catch (RuntimeException) {
            self::assertSame(0, $repo->acquisitions);
        }
        $repo->concurrent = false;
        $runtime->record($this->scope(), 'run-1', $this->goal(), $this->actions(), $this->at());
        $key = 'workspace:ai-offline-autonomy-preview:v1:run-1';
        $repo->receipts[$key]['execution_authorized'] = true;
        $this->expectException(InvalidArgumentException::class);
        $runtime->record($this->scope(), 'run-1', $this->goal(), $this->actions(), $this->at());
    }

    public function test_untrusted_plan_denies_before_idempotency_claim(): void
    {
        [$runtime, $repo] = $this->fixture();
        $untrusted = $this->actions();
        $untrusted[0]['tool_id'] = 'email_send';
        $this->expectException(InvalidArgumentException::class);
        try {
            $runtime->record($this->scope(), 'run-1', $this->goal(), $untrusted, $this->at());
        } finally {
            self::assertSame(0, $repo->acquisitions);
        }
    }
}
