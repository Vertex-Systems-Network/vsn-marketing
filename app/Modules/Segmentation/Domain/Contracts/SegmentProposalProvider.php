<?php

namespace App\Modules\Segmentation\Domain\Contracts;

use App\Modules\Segmentation\Domain\SegmentProposalResponse;

interface SegmentProposalProvider
{
    /** Whether an approved route is configured for structured segment proposals. */
    public function available(): bool;

    /**
     * The provider receives only operator intent and allowlisted schema metadata.
     *
     * @param array<string, mixed> $schema
     */
    public function propose(string $intent, array $schema): SegmentProposalResponse;
}
