<?php

namespace App\Modules\Content\Domain\Authoring;

use App\Modules\Content\Domain\Document\ContentTree;
use InvalidArgumentException;

final readonly class CanonicalAuthoringDraft
{
    public function __construct(
        public string $workspaceId,
        public string $documentId,
        public string $baseVersionId,
        public string $createdByActorId,
        public AuthoringMode $mode,
        public ContentTree $tree,
        public string $sourceFingerprint,
        public ?AuthoringTarget $markupTarget = null,
        public ?string $sanitizedMarkupHash = null,
    ) {
        foreach ([
            'workspace id' => $this->workspaceId,
            'document id' => $this->documentId,
            'base version id' => $this->baseVersionId,
            'actor id' => $this->createdByActorId,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Authoring {$label} must not be empty.");
            }
        }

        self::assertSha256($this->sourceFingerprint, 'source fingerprint');

        if ($this->mode->requiresSanitizationEvidence()) {
            if ($this->markupTarget === null || $this->sanitizedMarkupHash === null) {
                throw new InvalidArgumentException('Safe markup authoring requires target and sanitizer evidence.');
            }

            self::assertSha256($this->sanitizedMarkupHash, 'sanitized markup hash');

            return;
        }

        if ($this->markupTarget !== null || $this->sanitizedMarkupHash !== null) {
            throw new InvalidArgumentException('Non-markup authoring cannot attach sanitizer evidence.');
        }
    }

    /**
     * @return array{
     *     authoring_mode: string,
     *     base_version_id: string,
     *     source_sha256: string,
     *     markup_target: string|null,
     *     sanitized_markup_sha256: string|null
     * }
     */
    public function provenance(): array
    {
        return [
            'authoring_mode' => $this->mode->value,
            'base_version_id' => $this->baseVersionId,
            'source_sha256' => strtolower($this->sourceFingerprint),
            'markup_target' => $this->markupTarget?->value,
            'sanitized_markup_sha256' => $this->sanitizedMarkupHash === null
                ? null
                : strtolower($this->sanitizedMarkupHash),
        ];
    }

    private static function assertSha256(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
            throw new InvalidArgumentException("Authoring {$label} must be a SHA-256 hex digest.");
        }
    }
}
