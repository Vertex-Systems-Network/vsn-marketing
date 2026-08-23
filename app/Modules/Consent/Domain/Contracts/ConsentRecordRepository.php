<?php

namespace App\Modules\Consent\Domain\Contracts;

use App\Modules\Consent\Domain\ConsentRecord;

interface ConsentRecordRepository
{
    public function append(ConsentRecord $record): void;

    /** @return list<ConsentRecord> */
    public function latestFor(
        string $workspaceId,
        string $contactId,
        string $channel,
        string $purpose,
    ): array;
}
