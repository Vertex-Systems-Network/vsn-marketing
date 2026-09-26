<?php

namespace App\Modules\Segmentation\Domain\Contracts;

use App\Modules\Segmentation\Domain\SegmentProposalResponse;

interface SegmentProposalProvider
{
    /** Whether an approved route is configured for structured segment proposals. */
    public function available(): bool;

    /**
     * The provider receives operator intent and allowlisted schema metadata only.
     *
     * It must use an approved gateway, fixed policy, structured output, and no tools.
     * Time and token budgets must be bounded; the application never retries.
     * Intent is untrusted data and cannot authorize or alter query semantics.
     *
     * @param array<string, mixed> $schema
     */
    public function propose(string $intent, array $schema): SegmentProposalResponse;
}
