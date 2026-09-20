<?php

namespace App\Modules\Content\Domain\Brand;

use InvalidArgumentException;

final readonly class BrandStyleToken
{
    public function __construct(
        public string $key,
        public BrandTokenKind $kind,
        public string|int|float|bool $value,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/i', $this->key) !== 1) {
            throw new InvalidArgumentException('Brand style token key must be a bounded stable identifier.');
        }

        if ($this->kind === BrandTokenKind::Number) {
            if (is_int($this->value) === false && is_float($this->value) === false) {
                throw new InvalidArgumentException('Brand number token value must be numeric.');
            }

            if (is_float($this->value) && is_finite($this->value) === false) {
                throw new InvalidArgumentException('Brand number token value must be finite.');
            }

            return;
        }

        if ($this->kind === BrandTokenKind::Boolean) {
            if (is_bool($this->value) === false) {
                throw new InvalidArgumentException('Brand boolean token value must be boolean.');
            }

            return;
        }

        if (is_string($this->value) === false || trim($this->value) === '') {
            throw new InvalidArgumentException("Brand {$this->kind->value} token value must be a non-empty string.");
        }

        if (strlen($this->value) > 512) {
            throw new InvalidArgumentException('Brand style token value must not exceed 512 bytes.');
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $this->value) === 1) {
            throw new InvalidArgumentException('Brand style token value contains forbidden control characters.');
        }
    }

    /** @return array{key: string, kind: string, value: string|int|float|bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind->value,
            'value' => $this->value,
        ];
    }
}
