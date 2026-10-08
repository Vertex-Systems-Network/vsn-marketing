<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCompatibilityAssessment;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorDeprecationObservation;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorLifecycleDecision;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorLifecycleReconciler;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Task0086ConnectorLifecycleTest extends TestCase
{
    public function test_patch_only_compatibility_is_scored_and_evidence_is_stable(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $assessment = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '1.2.1',
            ['contacts.read' => '2.4.0', 'contacts.write' => '1.0.0'],
            ['contacts.write' => '1.0.0', 'contacts.read' => '2.4.1'],
            $at,
        );

        self::assertSame('compatible', $assessment->status);
        self::assertSame(90, $assessment->score);
        self::assertSame('patch_only_compatible_update', $assessment->reason);
        self::assertSame($assessment->evidenceSha256, $assessment->toArray()['evidence_sha256']);
    }

    public function test_unknown_additive_and_minor_changes_fail_closed(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $added = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '1.2.0',
            ['contacts.read' => '1.0.0'],
            ['contacts.read' => '1.0.0', 'contacts.write' => '1.0.0'],
            $at,
        );
        $minor = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '1.3.0',
            ['contacts.read' => '1.0.0'], ['contacts.read' => '1.0.0'], $at,
        );

        self::assertSame('unknown', $added->status);
        self::assertSame(0, $added->score);
        self::assertSame('unknown', $minor->status);
        self::assertSame(0, $minor->score);
    }

    public function test_major_and_removed_capability_changes_are_incompatible(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $major = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '2.0.0',
            ['contacts.read' => '1.0.0'], ['contacts.read' => '1.0.0'], $at,
        );
        $removed = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '1.2.1',
            ['contacts.read' => '1.0.0'], [], $at,
        );

        self::assertSame('incompatible', $major->status);
        self::assertSame(0, $major->score);
        self::assertSame('incompatible', $removed->status);
    }

    public function test_deprecation_observation_alerts_without_automatic_upgrade(): void
    {
        $observation = new ConnectorDeprecationObservation(
            'workspace-a', 'example', '1.2.0', 'https://docs.example.test/changelog',
            hash('sha256', 'source'),
            new DateTimeImmutable('2026-10-08T00:00:00+00:00'),
            new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
        );

        self::assertTrue($observation->alertRequired());
        self::assertFalse($observation->sunsetDue());
        self::assertFalse($observation->toArray()['automatic_upgrade']);
    }

    public function test_sunset_requires_an_existing_deprecation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConnectorDeprecationObservation(
            'workspace-a', 'example', '1.2.0', 'https://docs.example.test/changelog',
            hash('sha256', 'source'),
            new DateTimeImmutable('2026-10-08T00:00:00+00:00'),
            null, new DateTimeImmutable('2026-11-01T00:00:00+00:00'),
        );
    }

    public function test_lifecycle_decision_is_deterministic_and_tenant_scoped(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $evidence = hash('sha256', 'compatibility-evidence');
        $key = hash('sha256', 'same-operation');
        $decision = new ConnectorLifecycleDecision(
            'workspace-a', 'example', 'rollback', 'breaking_contract_major_change',
            'operator-1', $key, $at, $evidence, hash('sha256', 'approved-rollback'),
        );
        $repeat = new ConnectorLifecycleDecision(
            'workspace-a', 'example', 'rollback', 'breaking_contract_major_change',
            'operator-1', $key, $at, $evidence, hash('sha256', 'approved-rollback'),
        );

        self::assertSame($decision->auditSha256, $repeat->auditSha256);
        self::assertSame('workspace-a', $decision->toArray()['workspace_id']);
        self::assertSame($evidence, $decision->toArray()['compatibility_evidence_sha256']);
    }

    public function test_rollback_without_explicit_candidate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConnectorLifecycleDecision(
            'workspace-a', 'example', 'rollback', 'recovery',
            'operator-1', hash('sha256', 'key'),
            new DateTimeImmutable('2026-10-08T00:00:00+00:00'),
            hash('sha256', 'evidence'),
        );
    }

    public function test_lifecycle_reconciler_blocks_unknown_changes_and_tracks_deprecation(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $reconciler = new ConnectorLifecycleReconciler();
        $unknown = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '1.3.0',
            ['contacts.read' => '1.0.0'], ['contacts.read' => '1.0.0'], $at,
        );
        $blocked = $reconciler->reconcile($unknown, null, null, $at);
        self::assertSame('blocked', $blocked->status);
        self::assertSame('blocked', $blocked->toArray()['status']);

        $compatible = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '1.2.0',
            ['contacts.read' => '1.0.0'], ['contacts.read' => '1.0.0'], $at,
        );
        $deprecated = new ConnectorDeprecationObservation(
            'workspace-a', 'example', '1.2.0', 'https://docs.example.test/changelog',
            hash('sha256', 'source'), $at, new DateTimeImmutable('2026-10-01T00:00:00+00:00'), null,
        );
        $degraded = $reconciler->reconcile($compatible, $deprecated, null, $at);
        self::assertSame('degraded', $degraded->status);
        self::assertNotNull($degraded->deprecationEvidenceSha256);
    }

    public function test_failed_reconciliation_is_blocked_and_idempotent_across_retries(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $assessment = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '2.0.0',
            ['contacts.read' => '1.0.0'], ['contacts.read' => '1.0.0'], $at,
        );
        $reconciler = new ConnectorLifecycleReconciler();
        $key = hash('sha256', 'failed-rollback-attempt');
        $first = $reconciler->reconcile($assessment, null, null, $at, 'rollback_execution_failed', $key);
        $retry = $reconciler->reconcile($assessment, null, null, $at, 'rollback_execution_failed', $key);

        self::assertSame('blocked', $first->status);
        self::assertSame('rollback_execution_failed', $retry->failureCode);
        self::assertSame($first->reconciliationKey, $retry->reconciliationKey);
        self::assertSame($first->evidenceSha256, $retry->evidenceSha256);
    }

    public function test_lifecycle_reconciliation_rejects_cross_workspace_evidence(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $assessment = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '1.2.0',
            ['contacts.read' => '1.0.0'], ['contacts.read' => '1.0.0'], $at,
        );
        $decision = new ConnectorLifecycleDecision(
            'workspace-b', 'example', 'disable', 'operator_request',
            'operator-1', hash('sha256', 'operation'), $at, hash('sha256', 'evidence'),
        );

        $reconciler = new ConnectorLifecycleReconciler();

        $this->expectException(InvalidArgumentException::class);
        $reconciler->reconcile($assessment, null, $decision, $at);
    }

    public function test_disable_and_rollback_decisions_are_reported_without_claiming_execution(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $assessment = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '1.2.0',
            ['contacts.read' => '1.0.0'], ['contacts.read' => '1.0.0'], $at,
        );
        $disable = new ConnectorLifecycleDecision(
            'workspace-a', 'example', 'disable', 'operator_request',
            'operator-1', hash('sha256', 'disable'), $at, $assessment->evidenceSha256,
        );
        $rollback = new ConnectorLifecycleDecision(
            'workspace-a', 'example', 'rollback', 'operator_request',
            'operator-1', hash('sha256', 'rollback'), $at, $assessment->evidenceSha256,
            hash('sha256', 'rollback-candidate'),
        );
        $reconciler = new ConnectorLifecycleReconciler();

        self::assertSame('disabled', $reconciler->reconcile($assessment, null, $disable, $at)->status);
        self::assertSame('rollback_pending', $reconciler->reconcile($assessment, null, $rollback, $at)->status);
        self::assertSame('operator_rollback_requires_execution_evidence', $reconciler->reconcile($assessment, null, $rollback, $at)->reason);
    }

    public function test_failed_reconciliation_requires_a_registered_code_and_idempotency_key(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $assessment = ConnectorCompatibilityAssessment::assess(
            'workspace-a', 'example', '1.2.0', '1.2.0',
            ['contacts.read' => '1.0.0'], ['contacts.read' => '1.0.0'], $at,
        );

        $reconciler = new ConnectorLifecycleReconciler();

        $this->expectException(InvalidArgumentException::class);
        $reconciler->reconcile($assessment, null, null, $at, 'unknown_failure');
    }
}
