<?php

namespace App\Modules\Content\Application\Preview;

use InvalidArgumentException;

final readonly class PreviewExecutionPlan
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public string $workspaceId,
        public string $renderArtifactIdentity,
        public string $renderInputIdentity,
        public string $renderTarget,
        public PreviewViewport $viewport,
        public PreviewIsolationPolicy $isolationPolicy,
        public string $regressionProfileVersion,
        public string $previewIdentity,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if (trim($this->workspaceId) === '') {
            throw new InvalidArgumentException('Preview workspace id must not be empty.');
        }

        self::assertSha256($this->renderArtifactIdentity, 'render artifact identity');
        self::assertSha256($this->renderInputIdentity, 'render input identity');
        self::assertSha256($this->previewIdentity, 'preview identity');

        if (preg_match('/^[a-z0-9][a-z0-9._+-]{0,127}$/i', $this->regressionProfileVersion) !== 1) {
            throw new InvalidArgumentException('Preview regression profile version must be a bounded stable identifier.');
        }

        if (trim($this->renderTarget) === '') {
            throw new InvalidArgumentException('Preview render target must not be empty.');
        }

        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported preview execution plan schema version: {$this->schemaVersion}");
        }
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'workspace_id' => $this->workspaceId,
            'render_artifact_identity' => $this->renderArtifactIdentity,
            'render_input_identity' => $this->renderInputIdentity,
            'render_target' => $this->renderTarget,
            'viewport' => $this->viewport->toArray(),
            'isolation_policy' => $this->isolationPolicy->toArray(),
            'regression_profile_version' => $this->regressionProfileVersion,
            'preview_identity' => $this->previewIdentity,
            'authoritative_source' => false,
        ];
    }

    private static function assertSha256(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
            throw new InvalidArgumentException("Preview {$label} must be a SHA-256 hex digest.");
        }
    }
}
