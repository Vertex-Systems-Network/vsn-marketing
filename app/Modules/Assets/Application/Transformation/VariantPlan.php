<?php

namespace App\Modules\Assets\Application\Transformation;

use App\Modules\Assets\Domain\Variant\ProcessorIdentity;
use App\Modules\Assets\Domain\Variant\TransformationSpec;
use InvalidArgumentException;
use JsonException;

final readonly class VariantPlan
{
    public function __construct(
        public string $workspaceId,
        public string $sourceOriginalId,
        public TransformationSpec $transformation,
        public ProcessorIdentity $processor,
        public string $idempotencyKey,
    ) {
        foreach ([
            'workspaceId' => $this->workspaceId,
            'sourceOriginalId' => $this->sourceOriginalId,
            'idempotencyKey' => $this->idempotencyKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Variant plan {$field} must not be empty.");
            }
        }

        if (mb_strlen($this->idempotencyKey) > 191) {
            throw new InvalidArgumentException('Variant plan idempotency key must not exceed 191 characters.');
        }
    }

    /** @return array{workspace_id: string, source_original_id: string, transformation_spec: array<string, mixed>, transformation_hash: string, processor_id: string, processor_version: string} */
    public function deterministicRequest(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'source_original_id' => $this->sourceOriginalId,
            'transformation_spec' => $this->transformation->toArray(),
            'transformation_hash' => $this->transformation->hash(),
            'processor_id' => $this->processor->id,
            'processor_version' => $this->processor->version,
        ];
    }

    /** @throws JsonException */
    public function deterministicRequestHash(): string
    {
        return hash('sha256', json_encode(
            $this->deterministicRequest(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /** @throws JsonException */
    public function isReplayEquivalentTo(self $other): bool
    {
        return hash_equals($this->deterministicRequestHash(), $other->deterministicRequestHash());
    }
}
