<?php

namespace App\Modules\Audit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuditEvent
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $brandId,
        public ?string $actorId,
        public string $action,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $evidence,
        public ?string $correlationId,
        public DateTimeImmutable $occurredAt,
    ) {
        if ($this->id === '' || $this->workspaceId === '' || $this->action === '') {
            throw new InvalidArgumentException('Audit event id, workspace id, and action are required.');
        }

        if (($this->subjectType === null) !== ($this->subjectId === null)) {
            throw new InvalidArgumentException('Audit subject type and id must either both be set or both be null.');
        }
    }
}
