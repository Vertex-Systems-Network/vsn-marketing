<?php

namespace App\Modules\Segmentation\Domain;

use Illuminate\Database\Query\Builder;

final readonly class CompiledSegment
{
    public function __construct(
        public Builder $query,
        public string $definitionHash,
        public string $evaluationFingerprint,
        public string $evaluatedAt,
        public int $estimatedCost,
        public int $timeoutMs,
    ) {}
}
