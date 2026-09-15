<?php

namespace App\Modules\Providers\Domain\SenderPolicy;

enum ProviderVolumeClassification: string
{
    case BelowThreshold = 'below_threshold';
    case HighVolume = 'high_volume';
    case ConfigurationRequired = 'configuration_required';
    case Unknown = 'unknown';
}
