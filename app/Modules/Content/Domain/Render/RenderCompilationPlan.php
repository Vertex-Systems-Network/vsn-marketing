<?php

namespace App\Modules\Content\Domain\Render;

use InvalidArgumentException;

final readonly class RenderCompilationPlan
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public string $workspaceId,
        public string $renderInputIdentity,
        public RenderTarget $target,
        public RendererIdentity $renderer,
        public string $configurationHash,
        public RendererExecutionPolicy $executionPolicy,
        public string $artifactIdentity,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if (trim($this->workspaceId) === '') {
            throw new InvalidArgumentException('Render compilation workspace id must not be empty.');
        }

        self::assertSha256($this->renderInputIdentity, 'render input identity');
        self::assertSha256($this->configurationHash, 'renderer configuration hash');
        self::assertSha256($this->artifactIdentity, 'artifact identity');

        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported render compilation plan schema version: {$this->schemaVersion}");
        }
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'workspace_id' => $this->workspaceId,
            'render_input_identity' => $this->renderInputIdentity,
            'target' => $this->target->value,
            'media_type' => $this->target->mediaType(),
            'renderer' => [
                ...$this->renderer->toArray(),
                'configuration_sha256' => $this->configurationHash,
            ],
            'execution_policy' => $this->executionPolicy->toArray(),
            'artifact_identity' => $this->artifactIdentity,
            'authoritative_source' => false,
        ];
    }

    private static function assertSha256(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
            throw new InvalidArgumentException("Render compilation {$label} must be a SHA-256 hex digest.");
        }
    }
}
