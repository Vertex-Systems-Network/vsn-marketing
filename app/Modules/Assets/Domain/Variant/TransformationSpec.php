<?php

namespace App\Modules\Assets\Domain\Variant;

use InvalidArgumentException;
use JsonException;

final readonly class TransformationSpec
{
    public const int SCHEMA_VERSION = 1;

    /** @param list<TransformationStep> $steps */
    public function __construct(
        public array $steps,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported asset transformation schema version.');
        }

        if ($this->steps === []) {
            throw new InvalidArgumentException('Asset transformation spec requires at least one step.');
        }

        if (count($this->steps) > 16) {
            throw new InvalidArgumentException('Asset transformation spec exceeds the maximum step count.');
        }

        foreach ($this->steps as $step) {
            if (($step instanceof TransformationStep) === false) {
                throw new InvalidArgumentException('Asset transformation steps must be typed TransformationStep values.');
            }
        }
    }

    /** @return array{schema_version: int, steps: list<array{operation: string, parameters: array<string, int|string|bool>}>} */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'steps' => array_map(
                static fn (TransformationStep $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }

    /** @throws JsonException */
    public function canonicalJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /** @throws JsonException */
    public function hash(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
