<?php

namespace App\Modules\Audit\Domain\Contracts;

use App\Modules\Audit\Domain\AuditEvent;

interface AuditEventRepository
{
    public function store(AuditEvent $event): void;
}
