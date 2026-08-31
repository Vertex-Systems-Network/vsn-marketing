<?php

namespace App\Modules\Providers\Domain;

enum ProviderReadinessStatus: string
{
    case Unknown = 'unknown';
    case ConfigurationRequired = 'configuration_required';
    case AuthRequired = 'auth_required';
    case ScopeRequired = 'scope_required';
    case ProviderReviewRequired = 'provider_review_required';
    case SandboxOnly = 'sandbox_only';
    case PrivateOnly = 'private_only';
    case Ready = 'ready';
    case Suspended = 'suspended';
    case Deprecated = 'deprecated';
    case Unavailable = 'unavailable';
}
