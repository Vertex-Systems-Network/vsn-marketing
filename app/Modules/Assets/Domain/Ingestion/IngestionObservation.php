<?php

namespace App\Modules\Assets\Domain\Ingestion;

use App\Modules\Assets\Domain\Asset\AssetKind;
use InvalidArgumentException;

final readonly class IngestionObservation
{
    public function __construct(
        public string $contentSha256,
        public string $observedMediaType,
        public int $byteSize,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationMs = null,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $this->contentSha256) !== 1) {
            throw new InvalidArgumentException('Asset contentSha256 must be a lowercase SHA-256 hex digest.');
        }

        if (preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~i', trim($this->observedMediaType)) !== 1) {
            throw new InvalidArgumentException('Observed asset media type is invalid.');
        }

        if ($this->byteSize < 1) {
            throw new InvalidArgumentException('Observed asset byte size must be positive.');
        }

        foreach (['width' => $this->width, 'height' => $this->height, 'durationMs' => $this->durationMs] as $field => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException("Observed asset {$field} must be null or positive.");
            }
        }

        if (($this->width === null) !== ($this->height === null)) {
            throw new InvalidArgumentException('Observed asset dimensions must provide width and height together.');
        }
    }

    public function assertAcceptedBy(
        IngestionPolicy $policy,
        AssetKind $kind,
        ?string $declaredMediaType = null,
        ?int $declaredByteSize = null,
    ): void {
        $observed = strtolower($this->observedMediaType);

        if ($this->byteSize > $policy->maxBytes) {
            throw new InvalidArgumentException('Observed asset exceeds the configured ingestion size limit.');
        }

        if (! $policy->allows($observed)) {
            throw new InvalidArgumentException('Observed asset media type is not allowlisted by ingestion policy.');
        }

        if (! $kind->acceptsMediaType($observed)) {
            throw new InvalidArgumentException('Observed asset media type is incompatible with canonical asset kind.');
        }

        if ($declaredMediaType !== null && strtolower(trim($declaredMediaType)) !== $observed) {
            throw new InvalidArgumentException('Declared asset media type does not match observed content.');
        }

        if ($declaredByteSize !== null && $declaredByteSize !== $this->byteSize) {
            throw new InvalidArgumentException('Declared asset byte size does not match observed content.');
        }
    }
}
