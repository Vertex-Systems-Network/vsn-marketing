<?php

namespace App\Modules\AI\Domain;

use InvalidArgumentException;

/**
 * Integrity digest for an independently sourced canary outcome snapshot.
 * This checksum is not a provider signature or permission to promote.
 */
final class BoundedAutonomyCanaryOutcomeEvidenceDigest
{
    private const array FIELDS = [
        'tenant', 'experiment_id', 'plan_sha256', 'analysis_sha256',
        'observed_at_unix', 'expires_at_unix', 'verified_independent_source',
        'assigned', 'exposed', 'outcomes', 'quarantined', 'crossovers',
        'low_trust_count',
    ];

    public static function sha256(array $snapshot): string
    {
        $canonical = [];
        foreach (self::FIELDS as $key) {
            if (! array_key_exists($key, $snapshot)) {
                throw new InvalidArgumentException('Incomplete canary outcome evidence manifest.');
            }

            $value = $snapshot[$key];
            if (in_array($key, ['assigned', 'exposed', 'outcomes'], true)) {
                if (! is_array($value)) {
                    throw new InvalidArgumentException('Canary arm evidence must be a map.');
                }
                ksort($value, SORT_STRING);
            }
            $canonical[$key] = $value;
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
