<?php

namespace App\Modules\Segmentation\Infrastructure\AI;

use App\Modules\Segmentation\Domain\Contracts\SegmentProposalProvider;
use App\Modules\Segmentation\Domain\SegmentProposalResponse;

final class UnavailableSegmentProposalProvider implements SegmentProposalProvider
{
    public function available(): bool
    {
        return false;
    }

    public function propose(string $intent, array $schema): SegmentProposalResponse
    {
        return SegmentProposalResponse::unavailable();
    }
}
