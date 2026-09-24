<?php

namespace App\Modules\Publishing\Domain\Publication;

enum PublicationProviderOutcomeCode: string
{
    case Ready = 'ready';
    case PolicyBoundaryDenied = 'policy_boundary_denied';
    case AuthorizationEvidenceDrift = 'authorization_evidence_drift';
    case ProviderDisconnected = 'provider_disconnected';
    case CredentialInvalid = 'credential_invalid';
    case AppReviewRestricted = 'app_review_restricted';
    case PermissionLost = 'permission_lost';
    case ProviderAuthorityStale = 'provider_authority_stale';
    case CapabilityVersionDrift = 'capability_version_drift';
    case CircuitOpen = 'circuit_open';
    case CircuitHalfOpen = 'circuit_half_open';
    case RateLimited = 'rate_limited';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderRetryable = 'provider_retryable';
    case ProviderRejected = 'provider_rejected';
    case ProviderUnknown = 'provider_unknown';
}
