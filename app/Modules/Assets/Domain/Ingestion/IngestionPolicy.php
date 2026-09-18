<?php

namespace App\Modules\Assets\Domain\Ingestion;

use InvalidArgumentException;

final readonly class IngestionPolicy
{
    /**
     * @param  list<string>  $allowedMediaTypes
     */
    public function __construct(
        public int $maxBytes,
        public array $allowedMediaTypes,
    ) {
        if ($this->maxBytes < 1) {
            throw new InvalidArgumentException('Asset ingestion maxBytes must be positive.');
        }

        if ($this->allowedMediaTypes === []) {
            throw new InvalidArgumentException('Asset ingestion policy requires at least one allowed media type.');
        }

        foreach ($this->allowedMediaTypes as $mediaType) {
            if (self::isValidMediaType($mediaType) === false) {
                throw new InvalidArgumentException("Invalid allowed media type: {$mediaType}");
            }
        }
    }

    public function allows(string $mediaType): bool
    {
        $mediaType = strtolower(trim($mediaType));

        foreach ($this->allowedMediaTypes as $allowed) {
            $allowed = strtolower($allowed);
            if ($allowed === $mediaType) {
                return true;
            }

            if (str_ends_with($allowed, '/*') && str_starts_with($mediaType, substr($allowed, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    private static function isValidMediaType(string $mediaType): bool
    {
        return preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/(?:[a-z0-9][a-z0-9!#$&^_.+-]*|\*)$~i', trim($mediaType)) === 1;
    }
}
