<?php

namespace App\Modules\Assets\Domain\Variant;

use InvalidArgumentException;

final readonly class ProcessorIdentity
{
    public function __construct(
        public string $id,
        public string $version,
    ) {
        foreach (['id' => $this->id, 'version' => $this->version] as $field => $value) {
            if (trim($value) === '' || mb_strlen($value) > 191) {
                throw new InvalidArgumentException("Asset processor {$field} must be non-empty and at most 191 characters.");
            }

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\/-]*$/', $value) !== 1) {
                throw new InvalidArgumentException("Asset processor {$field} contains unsupported characters.");
            }
        }
    }
}
