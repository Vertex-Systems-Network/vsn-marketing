<?php

namespace App\Modules\Segmentation\Domain\Contracts;

use App\Modules\Segmentation\Domain\SegmentProposalResponse;

interface SegmentProposalProvider
{
    /** Whether an approved route is configured for structured segment proposals. */
    public function available(): bool;

    /**
     * The provider receives only operator intent and allowlisted schema metadata. Implementations
     * must use an approved gateway, fixed policy, schema-constrained output, and no tools; intent
     * is untrusted data and can never authorize a query or bypass deterministic validation.
     *
     * @param array<string, mixed> $schema
     */
    public function propose(string $intent, array $schema): SegmentProposalResponse;
}
