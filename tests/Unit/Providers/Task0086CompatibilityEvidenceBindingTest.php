<?php

namespace Tests\Unit\Providers;

use App\Modules\Providers\Domain\ConnectorFactory\ConnectorCompatibilityAssessment;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorLifecycleDecision;
use App\Modules\Providers\Domain\ConnectorFactory\ConnectorLifecycleReconciler;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Task0086CompatibilityEvidenceBindingTest extends TestCase
{
    public function test_lifecycle_decision_must_reference_the_exact_assessment_being_reconciled(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $assessment = ConnectorCompatibilityAssessment::assess(
            'workspace-a',
            'example',
            '1.2.0',
            '1.2.0',
            ['contacts.read' => '1.0.0'],
            ['contacts.read' => '1.0.0'],
            $at,
        );
        $decision = new ConnectorLifecycleDecision(
            'workspace-a',
            'example',
            'disable',
            'operator_request',
            'operator-1',
            hash('sha256', 'disable-operation'),
            $at,
            hash('sha256', 'different-assessment'),
        );

        $this->expectException(InvalidArgumentException::class);
        (new ConnectorLifecycleReconciler)->reconcile($assessment, null, $decision, $at);
    }

    public function test_lifecycle_decision_accepts_the_exact_assessment_evidence(): void
    {
        $at = new DateTimeImmutable('2026-10-08T00:00:00+00:00');
        $assessment = ConnectorCompatibilityAssessment::assess(
            'workspace-a',
            'example',
            '1.2.0',
            '1.2.0',
            ['contacts.read' => '1.0.0'],
            ['contacts.read' => '1.0.0'],
            $at,
        );
        $decision = new ConnectorLifecycleDecision(
            'workspace-a',
            'example',
            'disable',
            'operator_request',
            'operator-1',
            hash('sha256', 'disable-operation'),
            $at,
            $assessment->evidenceSha256,
        );

        self::assertSame(
            'disabled',
            (new ConnectorLifecycleReconciler)->reconcile($assessment, null, $decision, $at)->status,
        );
    }
}
