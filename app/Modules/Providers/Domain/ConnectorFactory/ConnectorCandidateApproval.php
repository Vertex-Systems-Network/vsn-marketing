<?php

namespace App\Modules\Providers\Domain\ConnectorFactory;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ConnectorCandidateApproval
{
    public function __construct(
        public string $candidateId,
        public string $evidenceSha256,
        public string $approvalId,
        public string $approverId,
        public DateTimeImmutable $approvedAt,
    ) {
        foreach ([$candidateId, $evidenceSha256, $approvalId] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('Approval requires immutable SHA-256 identities.');
            }
        }

        if (trim($approverId) === '' || $approvedAt->getOffset() !== 0) {
            throw new InvalidArgumentException('Approval requires an identified approver and UTC timestamp.');
        }
    }
}
