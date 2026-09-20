<?php

namespace App\Modules\Providers\Domain\Templates;

enum ProviderTemplateDrift: string
{
    case InSync = 'in_sync';
    case MissingMapping = 'missing_mapping';
    case CapabilityUnavailable = 'capability_unavailable';
    case ProviderTemplateMissing = 'provider_template_missing';
    case CanonicalVersionAdvanced = 'canonical_version_advanced';
    case ExternalProviderChange = 'external_provider_change';
}
