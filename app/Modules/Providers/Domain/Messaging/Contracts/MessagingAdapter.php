<?php

namespace App\Modules\Providers\Domain\Messaging\Contracts;

use App\Modules\Providers\Domain\Messaging\MessagingCapability;
use App\Modules\Providers\Domain\Messaging\MessagingDecision;
use App\Modules\Providers\Domain\Messaging\MessagingIntent;
use DateTimeImmutable;

interface MessagingAdapter
{
    public function capability(): MessagingCapability;

    /** @param list<string> $grantedScopes */
    public function prepare(MessagingIntent $intent, array $grantedScopes, DateTimeImmutable $at): MessagingDecision;

    public function dispatch(MessagingIntent $intent): MessagingDecision;
}
