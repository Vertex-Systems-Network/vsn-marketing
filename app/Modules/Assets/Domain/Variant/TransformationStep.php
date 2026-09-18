<?php

namespace App\Modules\Assets\Domain\Variant;

use InvalidArgumentException;

final readonly class TransformationStep
{
    /** @param array<string, int|string|bool> $parameters */
    public function __construct(
        public TransformationOperation $operation,
        public array $parameters,
    ) {
        match ($this->operation) {
            TransformationOperation::Resize => $this->validateResize(),
            TransformationOperation::Crop => $this->validateCrop(),
            TransformationOperation::Convert => $this->validateConvert(),
            TransformationOperation::Quality => $this->validateQuality(),
        };
    }

    /** @return array{operation: string, parameters: array<string, int|string|bool>} */
    public function toArray(): array
    {
        $parameters = $this->parameters;
        ksort($parameters, SORT_STRING);

        return [
            'operation' => $this->operation->value,
            'parameters' => $parameters,
        ];
    }

    private function validateResize(): void
    {
        $this->assertOnlyKeys(['width', 'height', 'fit']);

        $width = $this->parameters['width'] ?? null;
        $height = $this->parameters['height'] ?? null;
        if ($width === null && $height === null) {
            throw new InvalidArgumentException('Resize transformation requires width or height.');
        }

        if ($width !== null) {
            $this->assertBoundedPositiveInteger('width', $width, 10_000);
        }

        if ($height !== null) {
            $this->assertBoundedPositiveInteger('height', $height, 10_000);
        }

        $fit = $this->parameters['fit'] ?? 'contain';
        if (is_string($fit) === false || in_array($fit, ['contain', 'cover', 'fill', 'inside', 'outside'], true) === false) {
            throw new InvalidArgumentException('Resize fit is unsupported.');
        }
    }

    private function validateCrop(): void
    {
        $this->assertOnlyKeys(['x', 'y', 'width', 'height']);

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (array_key_exists($key, $this->parameters) === false) {
                throw new InvalidArgumentException("Crop transformation requires {$key}.");
            }
        }

        foreach (['x', 'y'] as $key) {
            $value = $this->parameters[$key];
            if (is_int($value) === false || $value < 0 || $value > 100_000) {
                throw new InvalidArgumentException("Crop {$key} must be an integer between 0 and 100000.");
            }
        }

        $this->assertBoundedPositiveInteger('width', $this->parameters['width'], 10_000);
        $this->assertBoundedPositiveInteger('height', $this->parameters['height'], 10_000);
    }

    private function validateConvert(): void
    {
        $this->assertOnlyKeys(['media_type']);

        $mediaType = $this->parameters['media_type'] ?? null;
        if (
            is_string($mediaType) === false
            || preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~i', $mediaType) !== 1
        ) {
            throw new InvalidArgumentException('Convert transformation requires a concrete media_type.');
        }

        if ($mediaType !== strtolower($mediaType)) {
            throw new InvalidArgumentException('Convert media_type must be normalized lowercase.');
        }
    }

    private function validateQuality(): void
    {
        $this->assertOnlyKeys(['quality']);

        $quality = $this->parameters['quality'] ?? null;
        if (is_int($quality) === false || $quality < 1 || $quality > 100) {
            throw new InvalidArgumentException('Quality transformation requires an integer quality between 1 and 100.');
        }
    }

    /** @param list<string> $allowed */
    private function assertOnlyKeys(array $allowed): void
    {
        foreach (array_keys($this->parameters) as $key) {
            if (is_string($key) === false || in_array($key, $allowed, true) === false) {
                throw new InvalidArgumentException("Unsupported {$this->operation->value} transformation parameter.");
            }
        }
    }

    private function assertBoundedPositiveInteger(string $field, mixed $value, int $max): void
    {
        if (is_int($value) === false || $value < 1 || $value > $max) {
            throw new InvalidArgumentException("Transformation {$field} must be an integer between 1 and {$max}.");
        }
    }
}
