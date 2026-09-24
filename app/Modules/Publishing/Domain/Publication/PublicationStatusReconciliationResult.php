<?php

namespace App\Modules\Publishing\Domain\Publication;

final readonly class PublicationStatusReconciliationResult
{
    public function __construct(
        public PublicationStatusObservation $observation,
        public PublicationStatusProjection $projection,
        public bool $projectionAdvanced,
    ) {}
}
