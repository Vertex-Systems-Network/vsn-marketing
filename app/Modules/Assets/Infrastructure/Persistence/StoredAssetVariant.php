<?php

namespace App\Modules\Assets\Infrastructure\Persistence;

use App\Modules\Assets\Domain\Ingestion\IngestionObservation;
use App\Modules\Assets\Domain\Variant\ProcessorIdentity;
use DateTimeImmutable;

final readonly class StoredAssetVariant
{
    /**
     * @param  array<string, mixed>  $transformationSpec
     * @param  array<string, mixed>  $auditProvenance
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $sourceOriginalId,
        public int $schemaVersion,
        public array $transformationSpec,
        public string $transformationHash,
        public ProcessorIdentity $processor,
        public IngestionObservation $output,
        public string $storageDisk,
        public string $storageKey,
        public array $auditProvenance,
        public string $idempotencyKey,
        public DateTimeImmutable $createdAt,
    ) {}
}
