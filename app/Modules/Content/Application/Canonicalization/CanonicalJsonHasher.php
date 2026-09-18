<?php

namespace App\Modules\Content\Application\Canonicalization;

use InvalidArgumentException;
use JsonException;

final class CanonicalJsonHasher
{
    /** @throws JsonException */
    public function hash(mixed $payload): string
    {
        return hash('sha256', $this->encode($payload));
    }

    /** @throws JsonException */
    public function encode(mixed $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_finite($value) === false) {
                throw new InvalidArgumentException('Canonical payload numbers must be finite.');
            }

            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            }

            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonicalize($item);
            }

            return $value;
        }

        throw new InvalidArgumentException('Canonical payload must be JSON-compatible and cannot contain executable objects or resources.');
    }
}
