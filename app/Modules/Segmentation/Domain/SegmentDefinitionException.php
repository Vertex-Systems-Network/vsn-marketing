<?php

namespace App\Modules\Segmentation\Domain;

use InvalidArgumentException;

final class SegmentDefinitionException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $path,
    ) {
        parent::__construct($reason.' at '.$path);
    }
}
