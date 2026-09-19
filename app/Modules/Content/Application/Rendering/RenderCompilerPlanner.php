<?php

namespace App\Modules\Content\Application\Rendering;

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use App\Modules\Content\Application\Canonicalization\RenderInputSnapshot;
use App\Modules\Content\Domain\Render\RenderCompilationPlan;
use App\Modules\Content\Domain\Render\RendererExecutionPolicy;
use App\Modules\Content\Domain\Render\RendererIdentity;
use App\Modules\Content\Domain\Render\RenderTarget;
use InvalidArgumentException;
use JsonException;

final readonly class RenderCompilerPlanner
{
    public function __construct(
        private CanonicalJsonHasher $hasher,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     *
     * @throws JsonException
     */
    public function plan(
        RenderInputSnapshot $input,
        RenderTarget $target,
        RendererIdentity $renderer,
        RendererExecutionPolicy $executionPolicy,
        array $configuration = [],
    ): RenderCompilationPlan {
        self::assertSafeConfiguration($configuration, 'configuration');

        $renderInputIdentity = $input->identity($this->hasher);
        $configurationHash = $this->hasher->hash($configuration);

        $artifactIdentity = $this->hasher->hash([
            'schema_version' => RenderCompilationPlan::SCHEMA_VERSION,
            'workspace_id' => $input->workspaceId,
            'render_input_identity' => $renderInputIdentity,
            'target' => $target->value,
            'renderer' => $renderer->toArray(),
            'configuration_sha256' => $configurationHash,
            'execution_policy' => $executionPolicy->toArray(),
        ]);

        return new RenderCompilationPlan(
            workspaceId: $input->workspaceId,
            renderInputIdentity: $renderInputIdentity,
            target: $target,
            renderer: $renderer,
            configurationHash: $configurationHash,
            executionPolicy: $executionPolicy,
            artifactIdentity: $artifactIdentity,
        );
    }

    private static function assertSafeConfiguration(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                if (is_string($key) === false) {
                    throw new InvalidArgumentException("Renderer configuration keys must be strings: {$path}");
                }

                if (preg_match(
                    '/(?:credential|secret|token|password|api[_-]?key|provider[_-]?payload|mime[_-]?payload|upload[_-]?id|command|shell|include|remote|network|filesystem|file[_-]?path|path)/i',
                    $key,
                ) === 1) {
                    throw new InvalidArgumentException("Renderer configuration cannot request privileged or provider-specific capability: {$path}.{$key}");
                }

                self::assertSafeConfiguration($nested, $path.'.'.$key);
            }

            return;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (
                preg_match('/^(?:https?|ftp|file|data):/i', $normalized) === 1
                || str_starts_with($normalized, '//')
                || str_starts_with($normalized, '/')
                || preg_match('/^[a-z]:[\\\\\/]/i', $normalized) === 1
            ) {
                throw new InvalidArgumentException("Renderer configuration cannot reference external or filesystem resources: {$path}");
            }

            return;
        }

        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            if (is_float($value) && is_finite($value) === false) {
                throw new InvalidArgumentException("Renderer configuration numbers must be finite: {$path}");
            }

            return;
        }

        throw new InvalidArgumentException("Renderer configuration must be JSON-compatible: {$path}");
    }
}
