<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOperatorDraft;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOperatorDraftTest extends TestCase
{
    private function actor(string $workspace = 'workspace'): TenantContext
    {
        return new TenantContext('org', $workspace, 'brand', 'operator');
    }

    private function date(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-09T00:00:00+00:00');
    }

    private function reports(): array
    {
        return [['id' => '11111111-1111-4111-8111-111111111111', 'fingerprint' => str_repeat('a', 64)]];
    }

    private function run(array $reports, int $target = 12, string $workspace = 'workspace'): array
    {
        return (new BoundedAutonomyOperatorDraft)->create(
            $this->actor($workspace), $reports,
            '11111111-1111-4111-8111-111111111111', $target,
            '22222222-2222-4222-8222-222222222222', $this->date(),
        );
    }

    public function test_issues_tenant_bound_read_only_offline_preview_from_registered_evidence(): void
    {
        $a = $this->run($this->reports());
        self::assertSame('preview_ready', $a['status']);
        self::assertSame('analytics_read', $a['actions'][0]['tool_id']);
        self::assertSame('read', $a['actions'][0]['effect']);
        self::assertSame('R0', $a['actions'][0]['risk']);
        self::assertSame(['11111111-1111-4111-8111-111111111111'], $a['actions'][0]['source_ids']);
        self::assertFalse($a['execution_authorized']);
        self::assertSame('disabled', $a['stages']['execute']);
        self::assertSame($a, $this->run($this->reports()));
        self::assertNotSame($a['snapshot_sha256'], $this->run($this->reports(), 13)['snapshot_sha256']);
        self::assertNotSame($a['snapshot_sha256'], $this->run($this->reports(), 12, 'another-workspace')['snapshot_sha256']);
    }

    public function test_missing_foreign_or_invalid_evidence_and_target_rejected(): void
    {
        $invalid = [[], [['id' => 'foreign', 'fingerprint' => str_repeat('a', 64)]],
            [['id' => '11111111-1111-4111-8111-111111111111', 'fingerprint' => 'bogus']],
            [['id' => '11111111-1111-4111-8111-111111111111', 'fingerprint' => null]]];
        foreach ($invalid as $reports) {
            try {
                $this->run($reports);
                self::fail('Unapproved analytics source accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([0, 1000001] as $target) {
            try {
                $this->run($this->reports(), $target);
                self::fail('Invalid target accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
