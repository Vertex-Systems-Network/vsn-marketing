<?php

namespace App\Modules\Segmentation\Domain\Contracts;

use App\Modules\Segmentation\Domain\SegmentProposalResponse;

interface SegmentProposalProvider
{
    /** Whether an approved route is configured for structured segment proposals. */
    public function available(): bool;

    // Approved gateway implementations receive only operator intent and allowlisted schema metadata.
    // They use fixed policy, structured output, no tools, bounded time and tokens, and no application retries.
    // Intent is untrusted data and cannot authorize or alter query semantics.
    /** @param array<string, mixed> $schema */
    public function propose(string $intent, array $schema): SegmentProposalResponse;
}
