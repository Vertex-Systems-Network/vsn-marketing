<?php

namespace App\Modules\Content\Application\Preview;

use App\Modules\Content\Application\Canonicalization\CanonicalJsonHasher;
use InvalidArgumentException;
use JsonException;

final readonly class PreviewRegressionResult
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<PreviewFinding>  $findings
     */
    private function __construct(
        public string $workspaceId,
        public string $previewIdentity,
        public string $renderArtifactIdentity,
        public string $renderInputIdentity,
        public string $status,
        public array $findings,
        public string $resultIdentity,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /**
     * @param  list<PreviewFinding>  $findings
     *
     * @throws JsonException
     */
    public static function fromFindings(
        PreviewExecutionPlan $plan,
        array $findings,
        CanonicalJsonHasher $hasher,
    ): self {
        if (count($findings) > $plan->isolationPolicy->maxFindings) {
            throw new InvalidArgumentException('Preview regression findings exceed the pinned isolation policy limit.');
        }

        foreach ($findings as $finding) {
            if ($finding instanceof PreviewFinding === false) {
                throw new InvalidArgumentException('Preview regression findings must contain PreviewFinding values.');
            }
        }

        usort(
            $findings,
            static fn (PreviewFinding $left, PreviewFinding $right): int => strcmp($left->sortKey(), $right->sortKey()),
        );

        $lastKey = null;
        foreach ($findings as $finding) {
            $key = $finding->sortKey();

            if ($lastKey === $key) {
                throw new InvalidArgumentException('Preview regression findings cannot contain exact duplicates.');
            }

            $lastKey = $key;
        }

        $status = self::deriveStatus($findings);
        $findingPayload = array_map(
            static fn (PreviewFinding $finding): array => $finding->toArray(),
            $findings,
        );

        $resultIdentity = $hasher->hash([
            'schema_version' => self::SCHEMA_VERSION,
            'workspace_id' => $plan->workspaceId,
            'preview_identity' => $plan->previewIdentity,
            'render_artifact_identity' => $plan->renderArtifactIdentity,
            'render_input_identity' => $plan->renderInputIdentity,
            'status' => $status,
            'findings' => $findingPayload,
        ]);

        return new self(
            workspaceId: $plan->workspaceId,
            previewIdentity: $plan->previewIdentity,
            renderArtifactIdentity: $plan->renderArtifactIdentity,
            renderInputIdentity: $plan->renderInputIdentity,
            status: $status,
            findings: $findings,
            resultIdentity: $resultIdentity,
        );
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'workspace_id' => $this->workspaceId,
            'preview_identity' => $this->previewIdentity,
            'render_artifact_identity' => $this->renderArtifactIdentity,
            'render_input_identity' => $this->renderInputIdentity,
            'status' => $this->status,
            'findings' => array_map(
                static fn (PreviewFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'result_identity' => $this->resultIdentity,
            'authoritative_source' => false,
        ];
    }

    /** @param  list<PreviewFinding>  $findings */
    private static function deriveStatus(array $findings): string
    {
        $hasWarning = false;

        foreach ($findings as $finding) {
            if ($finding->severity === 'error') {
                return 'failed';
            }

            if ($finding->severity === 'warning') {
                $hasWarning = true;
            }
        }

        return $hasWarning ? 'review' : 'passed';
    }
}
