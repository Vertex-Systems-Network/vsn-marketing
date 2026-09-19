<?php

namespace App\Modules\Content\Application\Preview;

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Domain\Render\RenderCompilationPlan;
use InvalidArgumentException;
use JsonException;

final readonly class PreviewPlanner
{
    public function __construct(
        private CanonicalJsonHasher $hasher,
    ) {}

    /** @throws JsonException */
    public function plan(
        RenderCompilationPlan $renderPlan,
        PreviewViewport $viewport,
        PreviewIsolationPolicy $isolationPolicy,
        string $regressionProfileVersion = 'vsn-preview-regression-v1',
    ): PreviewExecutionPlan {
        self::assertPolicyIsNoBroaderThanRenderer($renderPlan, $isolationPolicy);

        if (preg_match('/^[a-z0-9][a-z0-9._+-]{0,127}$/i', $regressionProfileVersion) !== 1) {
            throw new InvalidArgumentException('Preview regression profile version must be a bounded stable identifier.');
        }

        $previewIdentity = $this->hasher->hash([
            'schema_version' => PreviewExecutionPlan::SCHEMA_VERSION,
            'workspace_id' => $renderPlan->workspaceId,
            'render_artifact_identity' => $renderPlan->artifactIdentity,
            'render_input_identity' => $renderPlan->renderInputIdentity,
            'render_target' => $renderPlan->target->value,
            'viewport' => $viewport->toArray(),
            'isolation_policy' => $isolationPolicy->toArray(),
            'regression_profile_version' => $regressionProfileVersion,
        ]);

        return new PreviewExecutionPlan(
            workspaceId: $renderPlan->workspaceId,
            renderArtifactIdentity: $renderPlan->artifactIdentity,
            renderInputIdentity: $renderPlan->renderInputIdentity,
            renderTarget: $renderPlan->target->value,
            viewport: $viewport,
            isolationPolicy: $isolationPolicy,
            regressionProfileVersion: $regressionProfileVersion,
            previewIdentity: $previewIdentity,
        );
    }

    private static function assertPolicyIsNoBroaderThanRenderer(
        RenderCompilationPlan $renderPlan,
        PreviewIsolationPolicy $previewPolicy,
    ): void {
        $rendererPolicy = $renderPlan->executionPolicy;

        if ($previewPolicy->maxDurationMs > $rendererPolicy->maxDurationMs) {
            throw new InvalidArgumentException('Preview duration limit cannot exceed the pinned renderer duration limit.');
        }

        if ($previewPolicy->maxMemoryMb > $rendererPolicy->maxMemoryMb) {
            throw new InvalidArgumentException('Preview memory limit cannot exceed the pinned renderer memory limit.');
        }

        if ($previewPolicy->maxOutputBytes > $rendererPolicy->maxOutputBytes) {
            throw new InvalidArgumentException('Preview output limit cannot exceed the pinned renderer output limit.');
        }
    }
}
