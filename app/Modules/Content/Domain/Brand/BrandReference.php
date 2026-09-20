<?php

namespace App\Modules\Content\Domain\Brand;

use InvalidArgumentException;

final readonly class BrandReference
{
    public function __construct(
        public string $workspaceId,
        public string $brandKitId,
        public string $brandVersionId,
    ) {
        foreach ([
            'workspaceId' => $this->workspaceId,
            'brandKitId' => $this->brandKitId,
            'brandVersionId' => $this->brandVersionId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Brand reference {$field} must not be empty.");
            }
        }
    }

    public function key(): string
    {
        return $this->brandKitId.':'.$this->brandVersionId;
    }
}
