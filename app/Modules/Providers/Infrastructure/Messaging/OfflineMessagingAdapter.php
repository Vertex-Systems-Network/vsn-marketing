<?php

namespace App\Modules\Providers\Infrastructure\Messaging;

use App\Modules\Providers\Application\Messaging\EvaluateMessagingIntent;
use App\Modules\Providers\Domain\Messaging\Contracts\MessagingAdapter;
use App\Modules\Providers\Domain\Messaging\MessagingCapability;
use App\Modules\Providers\Domain\Messaging\MessagingDecision;
use App\Modules\Providers\Domain\Messaging\MessagingIntent;
use DateTimeImmutable;

final readonly class OfflineMessagingAdapter implements MessagingAdapter
{
    public function __construct(private MessagingCapability $capability, private EvaluateMessagingIntent $gate) {}

    public function capability(): MessagingCapability
    {
        return $this->capability;
    }

    public function prepare(MessagingIntent $intent, array $grantedScopes, DateTimeImmutable $at): MessagingDecision
    {
        return $this->gate->evaluate($intent, $this->capability, $grantedScopes, $at);
    }

    public function dispatch(MessagingIntent $intent): MessagingDecision
    {
        // Deliberately no HTTP/provider side effect, even after a valid offline plan.
        return new MessagingDecision(false, false, ['live_provider_unbound']);
    }
}
