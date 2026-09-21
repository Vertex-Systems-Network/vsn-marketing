<?php

namespace App\Modules\Publishing\Domain\Campaign;

use InvalidArgumentException;
use JsonException;

final class CampaignPayloadGuard
{
    public static function assertIdentifier(string $value, string $field, int $maxLength = 191): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("Campaign {$field} must not be empty.");
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Campaign {$field} must not exceed {$maxLength} characters.");
        }
    }

    /** @param list<string> $values */
    public static function assertIdentifierList(array $values, string $field): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException("Campaign {$field} must be a list.");
        }

        $seen = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("Campaign {$field} must contain string identifiers.");
            }

            self::assertIdentifier($value, $field);

            if (isset($seen[$value])) {
                throw new InvalidArgumentException("Campaign {$field} contains a duplicate identifier: {$value}");
            }

            $seen[$value] = true;
        }
    }

    public static function assertSha256(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException("Campaign {$field} must be a lowercase SHA-256 digest.");
        }
    }

    public static function assertPublicJson(mixed $value, string $path): void
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("Campaign JSON number must be finite: {$path}");
            }

            return;
        }

        if (is_string($value)) {
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException("Campaign JSON contains forbidden control characters: {$path}");
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $segment = (string) $key;

                if (
                    is_string($key)
                    && preg_match(
                        '/password|secret|token|authorization|credential|api[_-]?key|private[_-]?key|provider[_-]?payload|upload[_-]?id|container[_-]?id|provider[_-]?(?:post|media)[_-]?id|external[_-]?post[_-]?id/i',
                        $key,
                    ) === 1
                ) {
                    throw new InvalidArgumentException("Sensitive or transient provider campaign key is forbidden: {$path}.{$segment}");
                }

                self::assertPublicJson($nested, $path.'.'.$segment);
            }

            return;
        }

        throw new InvalidArgumentException("Campaign JSON must be JSON-compatible: {$path}");
    }

    public static function hash(mixed $payload): string
    {
        try {
            return hash('sha256', json_encode(
                self::canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Campaign canonical payload is not JSON-encodable.', previous: $exception);
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Campaign canonical payload numbers must be finite.');
            }

            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::canonicalize(...), $value);
            }

            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = self::canonicalize($item);
            }

            return $value;
        }

        throw new InvalidArgumentException('Campaign canonical payload must be JSON-compatible.');
    }
}
