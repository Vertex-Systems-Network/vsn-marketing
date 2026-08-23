<?php

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\CanonicalEvent;

final readonly class CanonicalEventPublished
{
    public function __construct(public CanonicalEvent $event) {}
}
