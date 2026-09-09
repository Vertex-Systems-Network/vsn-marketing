<?php

namespace App\Modules\DeliveryEngine\Domain;

enum DeliveryAttemptOutcomeClass: string
{
    case PermanentValidation = 'permanent_validation';
    case AuthOrPolicy = 'auth_or_policy';
    case RateLimited = 'rate_limited';
    case TransientPreAccept = 'transient_pre_accept';
    case TransientServer = 'transient_server';
    case AmbiguousTransport = 'ambiguous_transport';
    case ProviderAccepted = 'provider_accepted';
}
