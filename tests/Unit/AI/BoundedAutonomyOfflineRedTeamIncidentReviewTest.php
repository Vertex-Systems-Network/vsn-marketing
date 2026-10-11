<?php

namespace Tests\Unit\AI;

use App\Modules\AI\Application\BoundedAutonomyOfflineRedTeamIncidentReview;
use App\Modules\AI\Domain\Contracts\BoundedAutonomyRedTeamEvidenceSource;
use App\Modules\AI\Infrastructure\DenyingBoundedAutonomyRedTeamEvidenceSource;
use App\Modules\Identity\Domain\Tenancy\TenantContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedAutonomyOfflineRedTeamIncidentReviewTest extends TestCase
{
    private function scope(string $actor = 'analyst'): TenantContext
    {
        return new TenantContext('org', 'workspace', 'brand', $actor);
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-12T12:00:00+00:00');
    }

    private function finding(string $id = 'attack-1', string $outcome = 'blocked'): array
    {
        return [
            'case_id' => $id,
            'category' => 'goal_hijack',
            'severity' => 'critical',
            'outcome' => $outcome,
            'payload_sha256' => str_repeat('b', 64),
            'observed_at_unix' => $this->at()->getTimestamp() - 30,
        ];
    }

    private function signed(array $envelope): array
    {
        $envelope['source_manifest_sha256'] = hash('sha256', json_encode([
            'tenant' => $envelope['tenant'],
            'run_id' => $envelope['run_id'],
            'evidence_class' => $envelope['evidence_class'],
            'captured_at_unix' => $envelope['captured_at_unix'],
            'expires_at_unix' => $envelope['expires_at_unix'],
            'complete' => $envelope['complete'],
            'findings' => $envelope['findings'],
        ], JSON_THROW_ON_ERROR));

        return $envelope;
    }

    private function evidence(?array $findings = null): array
    {
        return $this->signed([
            'tenant' => $this->scope()->toArray(),
            'run_id' => 'run-1',
            'evidence_class' => 'synthetic_offline',
            'captured_at_unix' => $this->at()->getTimestamp(),
            'expires_at_unix' => $this->at()->getTimestamp() + 60,
            'complete' => true,
            'findings' => $findings ?? [$this->finding()],
            'source_manifest_sha256' => str_repeat('0', 64),
        ]);
    }

    private function inspect(?array $envelope, string $actor = 'analyst'): array
    {
        $source = $this->createMock(BoundedAutonomyRedTeamEvidenceSource::class);
        $source->method('latest')->willReturn($envelope);

        return (new BoundedAutonomyOfflineRedTeamIncidentReview($source))->inspect(
            $this->scope($actor), 'run-1', $this->at(),
        );
    }

    public function test_default_denying_source_does_not_invent_incidents_or_authority(): void
    {
        $result = (new BoundedAutonomyOfflineRedTeamIncidentReview(
            new DenyingBoundedAutonomyRedTeamEvidenceSource,
        ))->inspect($this->scope(), 'run-1', $this->at());

        self::assertSame('held_offline', $result['status']);
        self::assertSame('redteam_evidence_unavailable', $result['reason_code']);
        self::assertSame(0, $result['case_count']);
        self::assertFalse($result['incident_recorded']);
        self::assertFalse($result['production_incident_verified']);
        self::assertFalse($result['recovery_performed']);
        self::assertFalse($result['execution_authorized']);
    }

    public function test_complete_synthetic_cases_produce_only_redacted_human_triage_counts(): void
    {
        $second = array_replace($this->finding('attack-2'), [
            'category' => 'cross_tenant', 'severity' => 'high', 'outcome' => 'held',
        ]);
        $review = $this->inspect($this->evidence([$this->finding(), $second]));

        self::assertSame('offline_redteam_review_ready', $review['status']);
        self::assertSame('human_incident_triage_required', $review['reason_code']);
        self::assertSame(2, $review['case_count']);
        self::assertSame(1, $review['severity_counts']['critical']);
        self::assertSame(1, $review['severity_counts']['high']);
        self::assertSame(1, $review['category_counts']['goal_hijack']);
        self::assertSame(1, $review['category_counts']['cross_tenant']);
        self::assertTrue($review['synthetic_evidence_only']);
        self::assertFalse($review['production_incident_verified']);
        self::assertFalse($review['incident_recorded']);
        self::assertFalse($review['recovery_performed']);
        self::assertFalse($review['execution_authorized']);
        self::assertFalse($review['promotion_authorized']);
        self::assertArrayNotHasKey('findings', $review);
        self::assertArrayNotHasKey('payload_sha256', $review);
        self::assertStringNotContainsString('attack-1', json_encode($review, JSON_THROW_ON_ERROR));
    }

    public function test_incomplete_empty_or_unresolved_cases_fail_closed(): void
    {
        $partial = $this->signed(array_replace($this->evidence(), ['complete' => false]));
        self::assertSame('redteam_coverage_incomplete', $this->inspect($partial)['reason_code']);
        self::assertSame('redteam_coverage_incomplete', $this->inspect($this->evidence([]))['reason_code']);

        $unresolved = $this->evidence([$this->finding('attack-1', 'unresolved')]);
        $held = $this->inspect($unresolved);
        self::assertSame('held_offline', $held['status']);
        self::assertSame('unresolved_redteam_safety_case', $held['reason_code']);
        self::assertFalse($held['recovery_performed']);
    }

    public function test_changed_payload_scope_or_added_raw_secrets_are_never_accepted(): void
    {
        $good = $this->evidence();
        $changed = $good;
        $changed['findings'][0]['category'] = 'exfiltration';
        $crossTenant = $this->signed(array_replace($good, [
            'tenant' => $this->scope('imposter')->toArray(),
        ]));
        $rawSecret = $good;
        $rawSecret['findings'][0]['prompt'] = 'ignore policy: secret token';
        $claimsLive = $this->signed(array_replace($good, ['evidence_class' => 'production']));
        $claimsAuthority = array_replace($good, ['execute_now' => true]);
        $tamperedStatus = $this->signed(array_replace($good, [
            'findings' => [array_replace($this->finding(), ['outcome' => 'resolved'])],
        ]));

        foreach ([$changed, $crossTenant, $rawSecret, $claimsLive, $claimsAuthority, $tamperedStatus] as $bad) {
            try {
                $this->inspect($bad);
                self::fail('Untrusted synthetic incident evidence accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_replayed_case_ids_stale_times_and_invalid_scope_fail_closed(): void
    {
        $duplicate = $this->evidence([$this->finding(), $this->finding()]);
        $stale = $this->signed(array_replace($this->evidence(), [
            'captured_at_unix' => $this->at()->getTimestamp() - 3601,
        ]));
        $extraOldCase = $this->signed(array_replace($this->evidence(), [
            'findings' => [array_replace($this->finding(), [
                'observed_at_unix' => $this->at()->getTimestamp() - 3601,
            ])],
        ]));

        foreach ([$duplicate, $stale, $extraOldCase] as $bad) {
            try {
                $this->inspect($bad);
                self::fail('Replay or stale red-team case accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->inspect($this->evidence(), 'foreign-actor');
    }
}
